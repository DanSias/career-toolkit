<?php

namespace App\Support\JobAnalysis;

use App\Enums\JobAnalysisEmphasis;
use App\Enums\JobAnalysisFindingBasis;
use App\Enums\JobAnalysisFindingCategory;
use App\Enums\JobAnalysisMaturity;
use App\Enums\JobAnalysisRequirementStrength;

/**
 * One finding from an already-validated, already evidence-verified
 * provider response. Not an Eloquent model — see GenerateJobAnalysis
 * for where this is finally persisted as a JobAnalysisFinding plus its
 * JobAnalysisFindingEvidence children.
 */
final readonly class JobAnalysisFindingDraft
{
    /**
     * @param  array<int, JobAnalysisFindingEvidenceDraft>  $evidence
     */
    public function __construct(
        public JobAnalysisFindingCategory $category,
        public string $statement,
        public ?string $label,
        public JobAnalysisFindingBasis $basis,
        public ?JobAnalysisRequirementStrength $requirementStrength,
        public ?JobAnalysisEmphasis $emphasis,
        public ?JobAnalysisMaturity $maturity,
        public ?float $yearsExperienceMin,
        public ?float $yearsExperienceMax,
        public ?string $recencyRequirement,
        public ?string $timeHorizon,
        public ?string $notes,
        public array $evidence,
    ) {}
}
