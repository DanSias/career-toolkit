<?php

namespace App\Support\ResumeVariant;

/**
 * The complete, trusted, deterministically-validated output of the
 * Selection stage — evidence, structure, and claim posture, with no
 * employer-facing prose anywhere. This is what Stage 2 (Wording) is
 * built from, and what gets persisted into ResumeVariant's relational
 * tree once Wording also succeeds.
 */
final readonly class ResumeSelectionDraft
{
    /**
     * @param  array<int, string>  $summaryEvidenceFactKeys
     * @param  array<int, SkillSelectionDraft>  $skills
     * @param  array<int, EducationSelectionDraft>  $educationSelections
     * @param  array<int, RoleSelectionDraft>  $experience
     * @param  array<int, TargetTermUsageDraft>  $targetTermUsages
     */
    public function __construct(
        public array $summaryEvidenceFactKeys,
        public array $skills,
        public array $educationSelections,
        public array $experience,
        public array $targetTermUsages,
    ) {}
}
