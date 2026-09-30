<?php

namespace Tests\Support;

use App\Support\DisposableDatabase;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/**
 * Shared scratch-connection helpers for migration regression tests
 * that must seed real old-schema data and then run the REAL migration
 * files against it (never a simulation) — see
 * tests/Feature/Domain/ExperienceRoleSnapshotMigrationTest.php and
 * tests/Feature/Domain/SkillEducationSelectionSnapshotMigrationTest.php.
 * Mirrors exactly how
 * App\Console\Commands\LiveEval\RunResumeVariantLiveEvaluation manages
 * its own separate, named connection.
 */
final class MigrationScratchConnection
{
    public const NAME = 'sqlite_migration_test';

    /** Build exactly the schema before the named migration, independent of later files. */
    public static function setUpBefore(string $migration): string
    {
        $files = glob(database_path('migrations/*.php')) ?: [];
        sort($files);
        $boundary = database_path('migrations/'.$migration.'.php');
        if (! in_array($boundary, $files, true)) {
            throw new \InvalidArgumentException("Unknown migration boundary: {$migration}");
        }

        $path = tempnam(sys_get_temp_dir(), 'career_toolkit_test_');
        if ($path === false) {
            throw new \RuntimeException('Could not create disposable migration database.');
        }
        DB::purge(self::NAME);
        config(['database.connections.'.self::NAME => [
            'driver' => 'sqlite',
            'database' => $path,
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]]);

        if (! DisposableDatabase::allowsDestructiveCommands(self::NAME)) {
            throw new \RuntimeException('Migration scratch target is not disposable.');
        }
        $before = array_values(array_filter($files, fn (string $file) => $file < $boundary));
        $exit = Artisan::call('migrate', ['--database' => self::NAME, '--path' => $before, '--realpath' => true, '--force' => true]);
        if ($exit !== 0 || self::db()->table('migrations')->where('migration', '>=', $migration)->exists()
            || self::db()->table('migrations')->count() !== count($before)) {
            throw new \RuntimeException('Failed to establish the requested migration boundary.');
        }

        return $path;
    }

    public static function tearDown(): void
    {
        if (! config('database.connections.'.self::NAME)) {
            return;
        }

        $path = config('database.connections.'.self::NAME.'.database');
        DB::purge(self::NAME);

        if (is_string($path) && file_exists($path)) {
            unlink($path);
        }
    }

    public static function db(): Connection
    {
        return DB::connection(self::NAME);
    }

    /**
     * @return array{profile_id: int, role_ids: array<string, int>}
     */
    public static function seedCandidate(): array
    {
        $db = self::db();

        $userId = $db->table('users')->insertGetId(['name' => 'Test', 'email' => 't@example.com', 'password' => 'x']);
        $profileId = $db->table('career_profiles')->insertGetId(['user_id' => $userId, 'name' => 'Test']);
        $rocketgateId = $db->table('employers')->insertGetId(['career_profile_id' => $profileId, 'name' => 'RocketGate', 'sort_order' => 0]);
        $pearsonId = $db->table('employers')->insertGetId(['career_profile_id' => $profileId, 'name' => 'Pearson', 'sort_order' => 1]);

        $rocketgateRoleId = $db->table('roles')->insertGetId([
            'employer_id' => $rocketgateId, 'title' => 'Developer Support Engineer',
            'start_year' => 2025, 'start_month' => 5, 'end_year' => null, 'end_month' => null, 'sort_order' => 0,
        ]);
        $pearsonRoleId = $db->table('roles')->insertGetId([
            'employer_id' => $pearsonId, 'title' => 'Data & Analytics Lead Developer / Data Analyst',
            'start_year' => 2015, 'start_month' => 9, 'end_year' => 2024, 'end_month' => 7, 'sort_order' => 0,
        ]);

        return ['profile_id' => $profileId, 'role_ids' => ['rocketgate' => $rocketgateRoleId, 'pearson' => $pearsonRoleId]];
    }

    public static function seedResumeVariant(int $profileId, string $summary = 'Old summary'): int
    {
        $db = self::db();

        $postingId = $db->table('job_postings')->insertGetId(['career_profile_id' => $profileId, 'company' => 'Pearly', 'title' => 'SWE', 'description' => 'x']);
        $analysisId = $db->table('job_analyses')->insertGetId([
            'job_posting_id' => $postingId, 'schema_version' => '1.0', 'prompt_version' => 'v1',
            'generated_at' => now(), 'role_summary' => 'x', 'overall_seniority' => 'mid', 'seniority_rationale' => 'x',
        ]);
        $matchId = $db->table('job_matches')->insertGetId([
            'job_analysis_id' => $analysisId, 'career_profile_id' => $profileId, 'schema_version' => '1.0',
            'prompt_version' => 'v1', 'generated_at' => now(), 'input_snapshot' => '{}',
        ]);

        return $db->table('resume_variants')->insertGetId([
            'career_profile_id' => $profileId, 'job_match_id' => $matchId, 'schema_version' => '1.0',
            'selection_prompt_version' => 'v1', 'wording_prompt_version' => 'v1', 'generated_at' => now(),
            'selection_input_snapshot' => '{}', 'wording_input_snapshot' => '{}', 'summary' => $summary,
        ]);
    }

    public static function seedBullet(int $variantId, int $employerId, int $roleId, string $displayTitle, int $order, string $text): int
    {
        return self::db()->table('resume_variant_experience_bullets')->insertGetId([
            'resume_variant_id' => $variantId, 'employer_id' => $employerId, 'role_id' => $roleId,
            'project_id' => null, 'display_title' => $displayTitle, 'display_order' => $order, 'text' => $text,
        ]);
    }

    public static function seedProject(int $roleId, string $name, string $slug): int
    {
        return self::db()->table('projects')->insertGetId([
            'role_id' => $roleId, 'name' => $name, 'slug' => $slug, 'sort_order' => 0,
        ]);
    }

    public static function seedSkill(int $profileId, string $name, string $slug, string $category): int
    {
        return self::db()->table('skills')->insertGetId([
            'career_profile_id' => $profileId, 'name' => $name, 'slug' => $slug, 'category' => $category,
        ]);
    }

    public static function seedSkillSelection(int $variantId, int $skillId, int $order): int
    {
        return self::db()->table('resume_variant_skill_selections')->insertGetId([
            'resume_variant_id' => $variantId, 'skill_id' => $skillId, 'display_order' => $order,
        ]);
    }

    public static function seedEducation(int $profileId, string $institution, string $degree, ?string $fieldOfStudy, ?int $startYear, ?int $endYear): int
    {
        return self::db()->table('educations')->insertGetId([
            'career_profile_id' => $profileId, 'institution' => $institution, 'degree' => $degree,
            'field_of_study' => $fieldOfStudy, 'start_year' => $startYear, 'end_year' => $endYear, 'sort_order' => 0,
        ]);
    }

    public static function seedEducationSelection(int $variantId, int $educationId, int $order): int
    {
        return self::db()->table('resume_variant_education_selections')->insertGetId([
            'resume_variant_id' => $variantId, 'education_id' => $educationId, 'display_order' => $order,
        ]);
    }

    /**
     * The old schema already FK-protects skill_id/education_id
     * (restrictOnDelete), so a genuinely nonexistent id is structurally
     * impossible in real data — the same reason
     * ExperienceRoleSnapshotMigrationTest can't construct a "ghost
     * role_id" bullet either. Used only to exercise the backfillers'
     * defensive abort path with a deliberately-artificial row, the way
     * a real bug or an out-of-band data-repair mistake might produce
     * one; never representative of data this schema's own constraints
     * would normally allow.
     */
    public static function withoutForeignKeys(callable $callback): mixed
    {
        $db = self::db();
        $db->statement('PRAGMA foreign_keys = OFF');

        try {
            return $callback();
        } finally {
            $db->statement('PRAGMA foreign_keys = ON');
        }
    }
}
