<?php

namespace App\Support\ResumeVariant;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * One-time historical backfill for the ATS Resume Renderer milestone's
 * institution/degree/field_of_study/start_year/end_year snapshot
 * fields on resume_variant_education_selections (see the
 * 2026_09_09_000004 migration for the full safe up()/down() sequence
 * this is invoked from). Mirrors ExperienceRoleSnapshotBackfiller and
 * SkillSelectionSnapshotBackfiller: raw query-builder only, reads the
 * still-referenced live Education row via the existing education_id
 * FK, and never infers or fabricates a missing value.
 *
 * `field_of_study`/`start_year`/`end_year` are genuinely nullable on
 * both Education and this snapshot (see docs/domain-model.md
 * "Education" — not every degree has an evidenced field or start
 * year) — a null there is a real, faithfully-copied value, not
 * incomplete data. Only `institution`/`degree` are required; a live
 * Education row missing either (which the Education schema itself
 * already disallows, since both are NOT NULL there) aborts the
 * backfill rather than being papered over.
 */
final class EducationSelectionSnapshotBackfiller
{
    public function __construct(private readonly ?string $connection = null) {}

    /**
     * @return array{selections_updated: int}
     */
    public function run(): array
    {
        $db = DB::connection($this->connection);

        $selections = $db->table('resume_variant_education_selections')
            ->whereNull('institution')
            ->orderBy('id')
            ->get();

        $updated = 0;

        foreach ($selections as $selection) {
            $educationRow = $db->table('educations')->where('id', $selection->education_id)->first();
            $education = $educationRow === null ? null : (array) $educationRow;

            if ($education === null) {
                throw new RuntimeException(
                    "Education-selection backfill aborted: selection #{$selection->id} references education_id "
                    ."[{$selection->education_id}], which does not resolve to a live Education row. "
                    .'Refusing to reconstruct a snapshot from incomplete data — no rows were dropped.'
                );
            }

            if (! isset($education['institution'], $education['degree']) || $education['institution'] === '' || $education['degree'] === '') {
                throw new RuntimeException(
                    "Education-selection backfill aborted: Education #{$selection->education_id} (referenced by "
                    ."selection #{$selection->id}) has no institution/degree to preserve — refusing to invent one."
                );
            }

            $db->table('resume_variant_education_selections')
                ->where('id', $selection->id)
                ->update([
                    'institution' => $education['institution'],
                    'degree' => $education['degree'],
                    'field_of_study' => $education['field_of_study'] ?? null,
                    'start_year' => $education['start_year'] ?? null,
                    'end_year' => $education['end_year'] ?? null,
                ]);
            $updated++;
        }

        $incompleteCount = $db->table('resume_variant_education_selections')
            ->where(fn ($query) => $query->whereNull('institution')->orWhereNull('degree'))
            ->count();

        if ($incompleteCount > 0) {
            throw new RuntimeException(
                "Education-selection backfill aborted: {$incompleteCount} selection(s) remain incomplete "
                .'after reconstruction — refusing to proceed with incomplete data.'
            );
        }

        return ['selections_updated' => $updated];
    }
}
