<?php

namespace App\Support\CareerData;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * One-time historical backfill for the Project ownership model change
 * (see docs/domain-model.md "Project ownership"): every existing
 * Project was created before `projects.career_profile_id` existed and
 * has only a `role_id` — this resolves each one's owning CareerProfile
 * by walking `role_id -> roles.employer_id -> employers.career_profile_id`
 * and writes it onto the new column.
 *
 * Invoked by the 2026_09_09_000006 migration between adding
 * `career_profile_id` as nullable and finalizing it as required — see
 * that migration for the full up()/down() sequence. Extracted as its
 * own class specifically so it can be exercised directly against an
 * isolated scratch connection in a regression test (see
 * tests/Feature/Domain/ProjectOwnershipMigrationTest.php) without
 * needing to replay actual migration history mid-test, mirroring
 * App\Support\ResumeVariant\ExperienceRoleSnapshotBackfiller exactly.
 *
 * Raw query-builder only, deliberately not Eloquent: this must work
 * against the transient mid-migration schema shape (career_profile_id
 * nullable, role_id still required), which the current Project model
 * no longer describes once this migration has fully applied elsewhere.
 */
final class ProjectCareerProfileBackfiller
{
    public function __construct(private readonly ?string $connection = null) {}

    /**
     * @return array{projects_backfilled: int}
     */
    public function run(): array
    {
        $db = DB::connection($this->connection);

        $projects = $db->table('projects')
            ->whereNull('career_profile_id')
            ->orderBy('id')
            ->get();

        if ($projects->isEmpty()) {
            return ['projects_backfilled' => 0];
        }

        $backfilled = 0;

        foreach ($projects as $project) {
            $role = $db->table('roles')->where('id', $project->role_id)->first();
            $employer = $role === null ? null : $db->table('employers')->where('id', $role->employer_id)->first();

            if ($role === null || $employer === null) {
                throw new RuntimeException(
                    "Project ownership backfill aborted: project #{$project->id} references role_id ".
                    "[{$project->role_id}], which does not resolve to a live Role and Employer. ".
                    'Refusing to backfill from incomplete data — no rows were dropped, nothing was finalized.'
                );
            }

            $db->table('projects')
                ->where('id', $project->id)
                ->update(['career_profile_id' => $employer->career_profile_id]);
            $backfilled++;
        }

        $unresolvedCount = $db->table('projects')->whereNull('career_profile_id')->count();

        if ($unresolvedCount > 0) {
            throw new RuntimeException(
                "Project ownership backfill aborted: {$unresolvedCount} project(s) remain without a ".
                'resolved career_profile_id after reconstruction — refusing to proceed with incomplete data.'
            );
        }

        return ['projects_backfilled' => $backfilled];
    }
}
