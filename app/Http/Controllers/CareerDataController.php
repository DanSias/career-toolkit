<?php

namespace App\Http\Controllers;

use App\Models\CareerFact;
use App\Models\CareerProfile;
use App\Models\Education;
use App\Models\Employer;
use App\Models\Evidence;
use App\Models\Metric;
use App\Models\Project;
use App\Models\Role;
use App\Models\Skill;
use App\Support\CareerData\MetricFormatter;
use App\Support\CareerData\RoleDateFormatter;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Read-only. This controller only ever reads the canonical career
 * dataset — no create/update/delete action exists here, or anywhere else
 * for career data. The deterministic importer (`career:import`) remains
 * the only way canonical data changes.
 */
class CareerDataController extends Controller
{
    /**
     * A literal SQL fragment (not built from dynamic input) ordering
     * skills into a readable category order rather than alphabetical —
     * matches App\Enums\SkillCategory's own declared order.
     */
    private const SKILL_CATEGORY_ORDER_SQL = <<<'SQL'
        CASE category
            WHEN 'build_technology' THEN 0
            WHEN 'platform_integration' THEN 1
            WHEN 'capability' THEN 2
            WHEN 'practice' THEN 3
            ELSE 4
        END
        SQL;

    public function index(): Response
    {
        $profile = CareerProfile::query()
            ->with([
                'directCareerFacts',
                'employers' => fn ($query) => $query->orderBy('sort_order')->withCount('careerFacts'),
                'employers.roles' => fn ($query) => $query->orderBy('sort_order')->withCount('careerFacts'),
                'employers.roles.projects' => fn ($query) => $query->orderBy('sort_order')->withCount('careerFacts'),
                'employers.roles.projects.skills',
                'educations' => fn ($query) => $query->orderBy('sort_order'),
            ])
            ->first();

        if ($profile === null) {
            return Inertia::render('career-data/index', [
                'profile' => null,
                'employers' => [],
                'education' => [],
                'skills' => [],
            ]);
        }

        $skills = Skill::query()
            ->where('career_profile_id', $profile->id)
            ->withCount(['careerFacts', 'projects'])
            ->orderByRaw(self::SKILL_CATEGORY_ORDER_SQL)
            ->orderBy('name')
            ->get();

        return Inertia::render('career-data/index', [
            'profile' => [
                'name' => $profile->name,
                'facts' => $this->transformFactSummaries($profile->directCareerFacts),
            ],
            'employers' => $profile->employers->map($this->transformEmployer(...))->all(),
            'education' => $profile->educations->map($this->transformEducation(...))->all(),
            'skills' => $skills->map($this->transformSkill(...))->all(),
        ]);
    }

    public function showFact(CareerFact $careerFact): Response
    {
        $careerFact->load([
            'attributable' => fn (MorphTo $morphTo) => $morphTo->morphWith([
                Role::class => ['employer'],
                Project::class => ['role.employer'],
            ]),
            'evidence',
            'metric',
            'skills',
        ]);

        return Inertia::render('career-data/fact', [
            'fact' => $this->transformFactDetail($careerFact),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function transformEmployer(Employer $employer): array
    {
        return [
            'id' => $employer->id,
            'name' => $employer->name,
            'short_name' => $employer->short_name,
            'description' => $employer->description,
            'fact_count' => $employer->career_facts_count,
            'roles' => $employer->roles->map($this->transformRole(...))->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function transformRole(Role $role): array
    {
        $dates = RoleDateFormatter::format($role);

        return [
            'id' => $role->id,
            'title' => $role->title,
            'date_label' => $dates['label'],
            'is_current' => $dates['is_current'],
            'fact_count' => $role->career_facts_count,
            'projects' => $role->projects->map($this->transformProject(...))->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function transformProject(Project $project): array
    {
        return [
            'slug' => $project->slug,
            'name' => $project->name,
            'description' => $project->description,
            'visibility' => $project->default_visibility?->value,
            'fact_count' => $project->career_facts_count,
            'skills' => $project->skills->map($this->transformSkillRef(...))->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function transformEducation(Education $education): array
    {
        return [
            'id' => $education->id,
            'institution' => $education->institution,
            'degree' => $education->degree,
            'field_of_study' => $education->field_of_study,
            'start_year' => $education->start_year,
            'end_year' => $education->end_year,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function transformSkill(Skill $skill): array
    {
        return [
            'slug' => $skill->slug,
            'name' => $skill->name,
            'category' => $skill->category->value,
            'career_fact_count' => $skill->career_facts_count,
            'project_count' => $skill->projects_count,
        ];
    }

    /**
     * @return array<string, string>
     */
    private function transformSkillRef(Skill $skill): array
    {
        return [
            'slug' => $skill->slug,
            'name' => $skill->name,
            'category' => $skill->category->value,
        ];
    }

    /**
     * @param  Collection<int, CareerFact>  $facts
     * @return array<int, array<string, mixed>>
     */
    private function transformFactSummaries(Collection $facts): array
    {
        return $facts->map($this->transformFactSummary(...))->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function transformFactSummary(CareerFact $fact): array
    {
        return [
            'key' => $fact->key,
            'fact_type' => $fact->fact_type->value,
            'statement' => $fact->statement,
            'verification' => $fact->verification->value,
            'visibility' => $fact->visibility->value,
            'has_metric' => $fact->metric()->exists(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function transformFactDetail(CareerFact $fact): array
    {
        return [
            'key' => $fact->key,
            'fact_type' => $fact->fact_type->value,
            'statement' => $fact->statement,
            'verification' => $fact->verification->value,
            'visibility' => $fact->visibility->value,
            'notes' => $fact->notes,
            'attribution' => $this->transformAttribution($fact),
            'metric' => $fact->metric ? $this->transformMetric($fact->metric) : null,
            'skills' => $fact->skills->map($this->transformSkillRef(...))->all(),
            'evidence' => $fact->evidence->map($this->transformEvidence(...))->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function transformAttribution(CareerFact $fact): array
    {
        $attributable = $fact->attributable;

        return match (true) {
            $attributable instanceof CareerProfile => [
                'type' => 'career_profile',
                'path' => ['Career-wide'],
            ],
            $attributable instanceof Employer => [
                'type' => 'employer',
                'path' => [$attributable->name],
            ],
            $attributable instanceof Role => [
                'type' => 'role',
                'path' => [$attributable->employer->name, $attributable->title],
            ],
            $attributable instanceof Project => [
                'type' => 'project',
                'path' => [
                    $attributable->role->employer->name,
                    $attributable->role->title,
                    $attributable->name,
                ],
            ],
            default => ['type' => 'unknown', 'path' => []],
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function transformMetric(Metric $metric): array
    {
        return [
            'display' => MetricFormatter::format($metric),
            'value' => (float) $metric->value,
            'value_max' => $metric->value_max !== null ? (float) $metric->value_max : null,
            'is_range' => $metric->isRange(),
            'unit' => $metric->unit,
            'comparator' => $metric->comparator?->value,
            'scope_note' => $metric->scope_note,
            'guardrail' => $metric->guardrail,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function transformEvidence(Evidence $evidence): array
    {
        return [
            'source' => $evidence->source->value,
            'document' => $evidence->document,
            'path' => $evidence->path,
            'section' => $evidence->section,
            'locator' => $evidence->locator,
            'quoted_text' => $evidence->quoted_text,
            'confirmed_at' => $evidence->confirmed_at?->toDateString(),
            'note' => $evidence->note,
        ];
    }
}
