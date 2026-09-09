<?php

namespace App\Support\ResumeVariant;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * One-time historical backfill for the ATS Resume Renderer milestone's
 * name/category snapshot fields on resume_variant_skill_selections
 * (see the 2026_09_09_000003 migration for the full safe up()/down()
 * sequence this is invoked from). Mirrors
 * ExperienceRoleSnapshotBackfiller exactly: raw query-builder only
 * (the current Eloquent model no longer describes the transient
 * mid-migration schema shape), reads the still-referenced live Skill
 * via the existing skill_id FK, and never infers or fabricates a
 * missing value — a Skill row with no real name/category aborts the
 * whole backfill rather than being papered over.
 */
final class SkillSelectionSnapshotBackfiller
{
    public function __construct(private readonly ?string $connection = null) {}

    /**
     * @return array{selections_updated: int}
     */
    public function run(): array
    {
        $db = DB::connection($this->connection);

        $selections = $db->table('resume_variant_skill_selections')
            ->whereNull('name')
            ->orderBy('id')
            ->get();

        $updated = 0;

        foreach ($selections as $selection) {
            $skillRow = $db->table('skills')->where('id', $selection->skill_id)->first();
            $skill = $skillRow === null ? null : (array) $skillRow;

            if ($skill === null) {
                throw new RuntimeException(
                    "Skill-selection backfill aborted: selection #{$selection->id} references skill_id "
                    ."[{$selection->skill_id}], which does not resolve to a live Skill. "
                    .'Refusing to reconstruct a snapshot from incomplete data — no rows were dropped.'
                );
            }

            if (! isset($skill['name'], $skill['category']) || $skill['name'] === '' || $skill['category'] === '') {
                throw new RuntimeException(
                    "Skill-selection backfill aborted: Skill #{$selection->skill_id} (referenced by selection "
                    ."#{$selection->id}) has no name/category to preserve — refusing to invent one."
                );
            }

            $db->table('resume_variant_skill_selections')
                ->where('id', $selection->id)
                ->update(['name' => $skill['name'], 'category' => $skill['category']]);
            $updated++;
        }

        $incompleteCount = $db->table('resume_variant_skill_selections')
            ->where(fn ($query) => $query->whereNull('name')->orWhereNull('category'))
            ->count();

        if ($incompleteCount > 0) {
            throw new RuntimeException(
                "Skill-selection backfill aborted: {$incompleteCount} selection(s) remain incomplete "
                .'after reconstruction — refusing to proceed with incomplete data.'
            );
        }

        return ['selections_updated' => $updated];
    }
}
