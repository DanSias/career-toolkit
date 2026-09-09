<?php

namespace App\Support\ResumeVariant;

/**
 * The complete, trusted, deterministically-validated output of the
 * Selection stage — evidence, structure, and claim posture, with no
 * employer-facing prose anywhere. This is what Stage 2 (Wording) is
 * built from, and what gets persisted into ResumeVariant's relational
 * tree once Wording also succeeds.
 *
 * Deliberately carries no education selection — Education is no
 * longer a Selection-stage decision at all (structural absence, the
 * same technique already used to keep Skills posture-free): every
 * resume-eligible Education record is included deterministically at
 * persistence time instead. See GenerateResumeVariant::generateFull().
 *
 * `selectedProjects` is independent-Project-only (0-3) — see
 * docs/domain-model.md "ResumeVariant" -> "Selected Projects". A
 * professional Project can never appear here; it is structurally
 * absent from this field's legal id space (see
 * ResumeSelectionPromptV1::jsonSchema()), never merely discouraged by
 * prompt wording.
 */
final readonly class ResumeSelectionDraft
{
    /**
     * @param  array<int, string>  $summaryEvidenceFactKeys
     * @param  array<int, SkillSelectionDraft>  $skills
     * @param  array<int, RoleSelectionDraft>  $experience
     * @param  array<int, ProjectSelectionDraft>  $selectedProjects
     * @param  array<int, TargetTermUsageDraft>  $targetTermUsages
     */
    public function __construct(
        public array $summaryEvidenceFactKeys,
        public array $skills,
        public array $experience,
        public array $selectedProjects,
        public array $targetTermUsages,
    ) {}
}
