<?php

namespace App\Support\ResumeVariant;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * One-time historical backfill for the ATS Resume Renderer milestone's
 * Option B schema change (see docs/domain-model.md "ResumeVariant" ->
 * "Experience role snapshots"): reconstructs one
 * resume_variant_experience_roles row per (resume_variant_id, role_id)
 * group of pre-existing resume_variant_experience_bullets rows, then
 * links every one of those bullets to its new snapshot.
 *
 * Invoked by the 2026_09_09_000002 migration between adding
 * resume_variant_experience_role_id as nullable and finalizing it as
 * required — see that migration for the full up()/down() sequence.
 * Extracted as its own class specifically so it can be exercised
 * directly against an isolated scratch connection in a regression
 * test (see tests/Feature/Domain/BackfillExperienceRoleSnapshotsTest.php)
 * without needing to replay actual migration history mid-test.
 *
 * Raw query-builder only, deliberately not Eloquent: this must work
 * against the transient mid-migration schema shape, which the current
 * Eloquent model classes no longer describe (they reflect only the
 * final, post-migration shape) — the same reasoning
 * RunResumeVariantLiveEvaluation already documents for why it never
 * assumes today's model classes describe a database it didn't create
 * itself.
 *
 * `employer_name`/`start_year`/`start_month`/`end_year`/`end_month`
 * are read from the CURRENT live Role/Employer — the one honest
 * choice available: those values were never historically snapshotted
 * before this schema existed, so "current, at backfill time" is the
 * closest available truth, not a retroactive claim about what was
 * true at original generation time. `display_title` is NOT re-derived
 * this way — it reuses each bullet's own already-persisted,
 * genuinely-historical value verbatim.
 */
final class ExperienceRoleSnapshotBackfiller
{
    public function __construct(private readonly ?string $connection = null) {}

    /**
     * @return array{roles_created: int, bullets_linked: int}
     */
    public function run(): array
    {
        $db = DB::connection($this->connection);

        $bullets = $db->table('resume_variant_experience_bullets')
            ->whereNull('resume_variant_experience_role_id')
            ->orderBy('id')
            ->get();

        if ($bullets->isEmpty()) {
            return ['roles_created' => 0, 'bullets_linked' => 0];
        }

        $groups = $bullets->groupBy(fn ($bullet) => "{$bullet->resume_variant_id}:{$bullet->role_id}");

        $rolesCreated = 0;
        $bulletsLinked = 0;

        foreach ($groups as $group) {
            $first = $group->first();

            $roleRow = $db->table('roles')->where('id', $first->role_id)->first();
            $role = $roleRow === null ? null : (array) $roleRow;
            $employerRow = $role === null ? null : $db->table('employers')->where('id', $role['employer_id'])->first();
            $employer = $employerRow === null ? null : (array) $employerRow;

            if ($role === null || $employer === null) {
                throw new RuntimeException(
                    "Experience-role backfill aborted: bullet #{$first->id} references role_id "
                    ."[{$first->role_id}], which does not resolve to a live Role and Employer. "
                    .'Refusing to reconstruct a snapshot from incomplete data — no rows were dropped.'
                );
            }

            if ($first->display_title === null || $first->display_title === '') {
                throw new RuntimeException(
                    "Experience-role backfill aborted: bullet #{$first->id} has no display_title to preserve."
                );
            }

            $experienceRoleId = $db->table('resume_variant_experience_roles')->insertGetId([
                'resume_variant_id' => $first->resume_variant_id,
                'role_id' => $first->role_id,
                'employer_name' => $employer['name'],
                'display_title' => $first->display_title,
                'start_year' => $role['start_year'],
                'start_month' => $role['start_month'],
                'end_year' => $role['end_year'],
                'end_month' => $role['end_month'],
                // Finalized in a second pass below, once every role
                // snapshot for this variant exists — ranking a role
                // requires seeing its siblings' dates too.
                'display_order' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $rolesCreated++;

            $bulletIds = $group->pluck('id')->all();
            $db->table('resume_variant_experience_bullets')
                ->whereIn('id', $bulletIds)
                ->update(['resume_variant_experience_role_id' => $experienceRoleId]);
            $bulletsLinked += count($bulletIds);
        }

        $this->finalizeDisplayOrder($db);

        $orphanCount = $db->table('resume_variant_experience_bullets')
            ->whereNull('resume_variant_experience_role_id')
            ->count();

        if ($orphanCount > 0) {
            throw new RuntimeException(
                "Experience-role backfill aborted: {$orphanCount} historical bullet(s) remain unlinked "
                .'after reconstruction — refusing to proceed with incomplete data.'
            );
        }

        return ['roles_created' => $rolesCreated, 'bullets_linked' => $bulletsLinked];
    }

    /**
     * Reverse-chronological rank by start_year/start_month, exactly
     * matching the deterministic ordering rule GenerateResumeVariant
     * (new generations) and the pre-migration renderer (historical
     * generations, read live from Role before this schema existed)
     * both already use — computed per resume_variant_id, since
     * display_order is only meaningful within one variant's own set
     * of roles.
     */
    private function finalizeDisplayOrder(ConnectionInterface $db): void
    {
        $byVariant = $db->table('resume_variant_experience_roles')->get()->groupBy('resume_variant_id');

        foreach ($byVariant as $roles) {
            $ordered = $roles
                ->sortByDesc(fn ($role) => sprintf('%04d-%02d', $role->start_year, $role->start_month ?? 1))
                ->values();

            foreach ($ordered as $index => $roleRow) {
                $db->table('resume_variant_experience_roles')
                    ->where('id', $roleRow->id)
                    ->update(['display_order' => $index]);
            }
        }
    }
}
