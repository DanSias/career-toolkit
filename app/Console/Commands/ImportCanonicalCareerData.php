<?php

namespace App\Console\Commands;

use App\Exceptions\CanonicalDataImportException;
use App\Models\CareerFact;
use App\Models\CareerProfile;
use App\Models\Education;
use App\Models\Employer;
use App\Models\Metric;
use App\Models\Project;
use App\Models\Role;
use App\Models\Skill;
use App\Models\User;
use Closure;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use JsonException;
use Throwable;

/**
 * Deterministically imports the reviewed canonical career dataset (JSON,
 * not an LLM) into the database.
 *
 * Every entity is resolved by a stable natural key (never a raw JSON
 * array position or a persisted auto-increment id) and upserted via
 * `updateOrCreate`/`sync`, so running this command twice against the
 * same file produces the same end state as running it once — and
 * re-running it after an edited file deterministically applies that
 * edit, rather than merely skipping because a row already exists. See
 * docs/domain-model.md "Deterministic import" for the full design.
 */
#[Signature('career:import
    {path? : Path to the canonical dataset JSON, relative to the project root — defaults to data/canonical-career-data.proposed.json}
    {--user-email=dan.sias@gmail.com : Email of the local user who owns the imported CareerProfile}')]
#[Description('Deterministically import the reviewed canonical career dataset into the database. Safe to re-run.')]
class ImportCanonicalCareerData extends Command
{
    public function handle(): int
    {
        $path = $this->argument('path') ?? 'data/canonical-career-data.proposed.json';
        $fullPath = $this->isAbsolutePath($path) ? $path : base_path($path);

        if (! is_file($fullPath)) {
            $this->error("Canonical dataset not found at [{$fullPath}].");

            return self::FAILURE;
        }

        $contents = file_get_contents($fullPath);

        if ($contents === false) {
            $this->error("Unable to read canonical dataset at [{$fullPath}].");

            return self::FAILURE;
        }

        try {
            /** @var array<string, mixed> $json */
            $json = json_decode($contents, associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            $this->error("Canonical dataset is not valid JSON: {$e->getMessage()}");

            return self::FAILURE;
        }

        try {
            $summary = DB::transaction(fn () => $this->import($json));
        } catch (Throwable $e) {
            $this->error('Import failed and was rolled back: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info('Import complete — database now reflects '.$fullPath.'.');
        foreach ($summary as $label => $count) {
            $this->line(sprintf('  %-12s %d', $label, $count));
        }

        return self::SUCCESS;
    }

    /**
     * Accepts an absolute path (Unix `/...` or Windows `C:\...`) as-is,
     * so the dataset path can be given as either project-relative
     * (resolved against base_path()) or absolute — useful for tests and
     * for a dataset that genuinely lives outside the project.
     */
    protected function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, DIRECTORY_SEPARATOR) || (bool) preg_match('#^[A-Za-z]:[\\\\/]#', $path);
    }

    /**
     * @param  array<string, mixed>  $json
     * @return array<string, int>
     */
    protected function import(array $json): array
    {
        $this->assertNoDuplicateNaturalKeys($json);

        $user = User::firstOrCreate(
            ['email' => $this->option('user-email')],
            ['name' => $json['career_profile']['name'], 'password' => Hash::make(Str::random(40))],
        );

        $profile = CareerProfile::updateOrCreate(
            ['user_id' => $user->id],
            [
                'name' => $json['career_profile']['name'],
                'email' => $json['career_profile']['email'] ?? null,
                'phone' => $json['career_profile']['phone'] ?? null,
                'location' => $json['career_profile']['location'] ?? null,
                'portfolio_url' => $json['career_profile']['portfolio_url'] ?? null,
                'github_url' => $json['career_profile']['github_url'] ?? null,
            ],
        );

        $employersByKey = $this->importEmployers($json['employers'], $profile);
        $rolesByKey = $this->importRoles($json['roles'], $employersByKey);
        $projectsByKey = $this->importProjects($json['projects'], $rolesByKey, $profile);
        $skillsByKey = $this->importSkills($json['skills'], $profile);
        $educationCount = $this->importEducations($json['educations'] ?? [], $profile);
        $this->syncProjectSkills($json['projects'], $projectsByKey, $skillsByKey);

        ['facts' => $factCount, 'evidence' => $evidenceCount, 'metrics' => $metricCount] = $this->importCareerFacts(
            $json['career_facts'], $profile, $employersByKey, $rolesByKey, $projectsByKey, $skillsByKey,
        );

        return [
            'Employers' => count($employersByKey),
            'Roles' => count($rolesByKey),
            'Projects' => count($projectsByKey),
            'Skills' => count($skillsByKey),
            'Education' => $educationCount,
            'CareerFacts' => $factCount,
            'Evidence' => $evidenceCount,
            'Metrics' => $metricCount,
        ];
    }

    /**
     * Fails loudly, before any write happens, if the dataset itself
     * would make two distinct real-world records collide under the same
     * natural key — e.g. two roles with the same employer/title/start.
     * This is what "fail rather than silently creating ambiguous
     * duplicate Employers/Roles" actually means for an updateOrCreate-
     * based importer: the ambiguity has to be caught in the source data,
     * since updateOrCreate itself would otherwise just silently merge
     * the second record into the first.
     *
     * @param  array<string, mixed>  $json
     */
    protected function assertNoDuplicateNaturalKeys(array $json): void
    {
        $this->assertUniqueWithinDataset($json['employers'], fn (array $e) => $e['name'], 'employer (by name)');
        $this->assertUniqueWithinDataset($json['employers'], fn (array $e) => $e['key'], 'employer (by proposal key)');

        $this->assertUniqueWithinDataset(
            $json['roles'],
            fn (array $r) => $r['employer_key'].'|'.$r['title'].'|'.$r['start_year'].'|'.($r['start_month'] ?? 'null'),
            'role (by employer, title, start year/month)',
        );
        $this->assertUniqueWithinDataset($json['roles'], fn (array $r) => $r['key'], 'role (by proposal key)');

        $this->assertUniqueWithinDataset($json['projects'], fn (array $p) => $p['key'], 'project (by slug)');
        $this->assertUniqueWithinDataset($json['skills'], fn (array $s) => $s['key'], 'skill (by slug)');

        $realFacts = array_values(array_filter($json['career_facts'], fn (array $f) => array_key_exists('key', $f)));
        $this->assertUniqueWithinDataset($realFacts, fn (array $f) => $f['key'], 'career fact (by key)');
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     */
    protected function assertUniqueWithinDataset(array $items, Closure $naturalKeyFn, string $label): void
    {
        $seen = [];

        foreach ($items as $item) {
            $key = $naturalKeyFn($item);

            if (isset($seen[$key])) {
                throw new CanonicalDataImportException(
                    "Duplicate {$label}: [{$key}] appears more than once in the dataset — refusing to import ambiguous records."
                );
            }

            $seen[$key] = true;
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $employers
     * @return array<string, Employer>
     */
    protected function importEmployers(array $employers, CareerProfile $profile): array
    {
        $byKey = [];

        foreach ($employers as $data) {
            $byKey[$data['key']] = Employer::updateOrCreate(
                ['career_profile_id' => $profile->id, 'name' => $data['name']],
                [
                    'short_name' => $data['short_name'] ?? null,
                    'description' => $data['description'] ?? null,
                    'sort_order' => $data['sort_order'] ?? 0,
                ],
            );
        }

        return $byKey;
    }

    /**
     * @param  array<int, array<string, mixed>>  $roles
     * @param  array<string, Employer>  $employersByKey
     * @return array<string, Role>
     */
    protected function importRoles(array $roles, array $employersByKey): array
    {
        $byKey = [];

        foreach ($roles as $data) {
            $employer = $employersByKey[$data['employer_key']]
                ?? throw new CanonicalDataImportException("Role [{$data['key']}] references unknown employer_key [{$data['employer_key']}].");

            $byKey[$data['key']] = Role::updateOrCreate(
                [
                    'employer_id' => $employer->id,
                    'title' => $data['title'],
                    'start_year' => $data['start_year'],
                    'start_month' => $data['start_month'],
                ],
                [
                    'end_year' => $data['end_year'],
                    'end_month' => $data['end_month'],
                    'summary' => $data['summary'] ?? null,
                    'sort_order' => $data['sort_order'] ?? 0,
                ],
            );
        }

        return $byKey;
    }

    /**
     * A Project entry with a `role_key` is professional, owned by that
     * Role; one with no `role_key` at all is independent, owned
     * directly by `$profile` — see docs/domain-model.md "Project
     * ownership". `career_profile_id` is always set explicitly to
     * `$profile->id` here rather than left to Project's own auto-fill
     * default, since the importer always knows the real owning profile
     * up front.
     *
     * @param  array<int, array<string, mixed>>  $projects
     * @param  array<string, Role>  $rolesByKey
     * @return array<string, Project>
     */
    protected function importProjects(array $projects, array $rolesByKey, CareerProfile $profile): array
    {
        $byKey = [];

        foreach ($projects as $data) {
            $roleId = null;

            if (array_key_exists('role_key', $data) && $data['role_key'] !== null) {
                $role = $rolesByKey[$data['role_key']]
                    ?? throw new CanonicalDataImportException("Project [{$data['key']}] references unknown role_key [{$data['role_key']}].");
                $roleId = $role->id;
            }

            $byKey[$data['key']] = Project::updateOrCreate(
                ['slug' => $data['key']],
                [
                    'career_profile_id' => $profile->id,
                    'role_id' => $roleId,
                    'name' => $data['name'],
                    'description' => $data['description'] ?? null,
                    'default_visibility' => $data['default_visibility'] ?? null,
                    'live_url' => $data['live_url'] ?? null,
                    'repository_url' => $data['repository_url'] ?? null,
                    'sort_order' => $data['sort_order'] ?? 0,
                ],
            );
        }

        return $byKey;
    }

    /**
     * @param  array<int, array<string, mixed>>  $skills
     * @return array<string, Skill>
     */
    protected function importSkills(array $skills, CareerProfile $profile): array
    {
        $byKey = [];

        foreach ($skills as $data) {
            $byKey[$data['key']] = Skill::updateOrCreate(
                ['career_profile_id' => $profile->id, 'slug' => $data['key']],
                [
                    'name' => $data['name'],
                    'category' => $data['category'],
                    'description' => $data['description'] ?? null,
                ],
            );
        }

        return $byKey;
    }

    /**
     * Resolves a JSON `skills` array (a list of skill keys) into real
     * Skill ids, failing loudly on any key that isn't in the resolved
     * skill map. Takes `mixed` deliberately — the value comes straight
     * out of a decoded JSON structure — and validates the shape itself,
     * rather than trusting a PHPDoc annotation to describe it.
     *
     * @param  array<string, Skill>  $skillsByKey
     * @return array<int, int>
     */
    protected function resolveSkillIds(mixed $skillKeys, array $skillsByKey, string $context): array
    {
        $ids = [];

        foreach ((array) $skillKeys as $skillKey) {
            $skillKey = (string) $skillKey;

            $skill = $skillsByKey[$skillKey]
                ?? throw new CanonicalDataImportException("{$context} references unknown skill key [{$skillKey}].");

            $ids[] = $skill->id;
        }

        return $ids;
    }

    /**
     * @param  array<int, array<string, mixed>>  $educations
     */
    protected function importEducations(array $educations, CareerProfile $profile): int
    {
        foreach ($educations as $data) {
            Education::updateOrCreate(
                [
                    'career_profile_id' => $profile->id,
                    'institution' => $data['institution'],
                    'degree' => $data['degree'],
                    'field_of_study' => $data['field_of_study'] ?? null,
                ],
                [
                    'start_year' => $data['start_year'] ?? null,
                    'end_year' => $data['end_year'] ?? null,
                    'sort_order' => $data['sort_order'] ?? 0,
                ],
            );
        }

        return count($educations);
    }

    /**
     * Project-level skill associations have no source data in the
     * dataset today (only CareerFacts carry a `skills` array) — this
     * still syncs whenever a `skills` key is present on a Project entry,
     * so it's correct if/when the proposal ever populates one, without
     * fabricating anything from today's data.
     *
     * @param  array<int, array<string, mixed>>  $projects
     * @param  array<string, Project>  $projectsByKey
     * @param  array<string, Skill>  $skillsByKey
     */
    protected function syncProjectSkills(array $projects, array $projectsByKey, array $skillsByKey): void
    {
        foreach ($projects as $data) {
            if (! array_key_exists('skills', $data)) {
                continue;
            }

            $project = $projectsByKey[$data['key']];

            $skillIds = $this->resolveSkillIds($data['skills'], $skillsByKey, "Project [{$data['key']}]");

            $project->skills()->sync($skillIds);
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $facts
     * @param  array<string, Employer>  $employersByKey
     * @param  array<string, Role>  $rolesByKey
     * @param  array<string, Project>  $projectsByKey
     * @param  array<string, Skill>  $skillsByKey
     * @return array{facts: int, evidence: int, metrics: int}
     */
    protected function importCareerFacts(
        array $facts,
        CareerProfile $profile,
        array $employersByKey,
        array $rolesByKey,
        array $projectsByKey,
        array $skillsByKey,
    ): array {
        $morphMap = Relation::morphMap();
        $factCount = 0;
        $evidenceCount = 0;
        $metricCount = 0;

        foreach ($facts as $data) {
            // Section-divider markers in the JSON carry no `key`.
            if (! array_key_exists('key', $data)) {
                continue;
            }

            $attributableType = $data['attributable']['type'];
            $attributableKey = $data['attributable']['key'];

            if (! array_key_exists($attributableType, $morphMap)) {
                throw new CanonicalDataImportException(
                    "CareerFact [{$data['key']}] has an unsupported attributable type [{$attributableType}]."
                );
            }

            $attributable = match ($attributableType) {
                'career_profile' => $profile,
                'employer' => $employersByKey[$attributableKey] ?? null,
                'role' => $rolesByKey[$attributableKey] ?? null,
                'project' => $projectsByKey[$attributableKey] ?? null,
                default => null,
            };

            if (! $attributable instanceof Model) {
                throw new CanonicalDataImportException(
                    "CareerFact [{$data['key']}] references unknown {$attributableType} key [{$attributableKey}]."
                );
            }

            $fact = CareerFact::updateOrCreate(
                ['key' => $data['key']],
                [
                    'career_profile_id' => $profile->id,
                    'fact_type' => $data['fact_type'],
                    'statement' => $data['statement'],
                    'verification' => $data['verification'],
                    'visibility' => $data['visibility'],
                    'notes' => $data['notes'] ?? null,
                    'sort_order' => $data['sort_order'] ?? 0,
                    'attributable_type' => $attributable->getMorphClass(),
                    'attributable_id' => $attributable->getKey(),
                ],
            );
            $factCount++;

            // Evidence has no natural key of its own — a full
            // delete-and-recreate per fact is the simplest correct way
            // to make a re-import deterministically reflect edited
            // Evidence, without a separate diffing mechanism.
            $fact->evidence()->delete();
            foreach ($data['evidence'] ?? [] as $evidenceData) {
                $fact->evidence()->create([
                    'source' => $evidenceData['source'],
                    'document' => $evidenceData['document'] ?? null,
                    'path' => $evidenceData['path'] ?? null,
                    'section' => $evidenceData['section'] ?? null,
                    'locator' => $evidenceData['locator'] ?? null,
                    'quoted_text' => $evidenceData['quoted_text'] ?? null,
                    'confirmed_at' => $evidenceData['confirmed_at'] ?? null,
                    'note' => $evidenceData['note'] ?? null,
                    'metadata' => $evidenceData['metadata'] ?? null,
                ]);
                $evidenceCount++;
            }

            if (! empty($data['metric'])) {
                Metric::updateOrCreate(
                    ['career_fact_id' => $fact->id],
                    [
                        'value' => $data['metric']['value'],
                        'value_max' => $data['metric']['value_max'] ?? null,
                        'unit' => $data['metric']['unit'],
                        'comparator' => $data['metric']['comparator'] ?? null,
                        'scope_note' => $data['metric']['scope_note'] ?? null,
                        'guardrail' => $data['metric']['guardrail'] ?? null,
                    ],
                );
                $metricCount++;
            } else {
                $fact->metric()->delete();
            }

            $skillIds = $this->resolveSkillIds($data['skills'] ?? [], $skillsByKey, "CareerFact [{$data['key']}]");
            $fact->skills()->sync($skillIds);
        }

        return ['facts' => $factCount, 'evidence' => $evidenceCount, 'metrics' => $metricCount];
    }
}
