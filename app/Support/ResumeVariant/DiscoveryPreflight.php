<?php

namespace App\Support\ResumeVariant;

use App\Enums\JobAnalysisEmphasis;
use App\Enums\JobAnalysisRequirementStrength;
use App\Enums\JobMatchCoverage;
use App\Models\JobMatch;

/**
 * Deterministic, no-provider-call gap detection run before Resume
 * Selection — surfaces a small number of high-value, possibly-missing
 * experiences worth confirming with the candidate before generating.
 * Entirely rule-based: the gate is already narrow enough in practice
 * (confirmed against real JobMatch live-eval data) that a model call to
 * prioritize among candidates isn't needed for v1. See
 * docs/domain-model.md "ResumeVariant".
 *
 * Never persisted (see docs/domain-model.md — deliberately no
 * discovery-response workflow-state table in v1: this is free and
 * re-runnable, and a confirmed "yes" always routes through the
 * canonical CareerFact workflow rather than becoming resume evidence
 * directly). Always skippable — the caller may generate straight from
 * documented evidence without ever calling this.
 */
final class DiscoveryPreflight
{
    private const MAX_CANDIDATES = 3;

    /**
     * @return array<int, array{job_analysis_finding_id: int, term: string, prompt: string, tier: int}>
     */
    public function run(JobMatch $jobMatch): array
    {
        $candidates = [];

        foreach ($jobMatch->findings()->with('jobAnalysisFinding')->get() as $matchFinding) {
            if ($matchFinding->coverage !== JobMatchCoverage::NoEvidence) {
                continue;
            }

            $finding = $matchFinding->jobAnalysisFinding;
            $tier = $this->tier($finding->requirement_strength, $finding->emphasis);

            if ($tier === null) {
                continue;
            }

            $term = $finding->label !== null && $finding->label !== ''
                ? str_replace('_', ' ', $finding->label)
                : $finding->statement;

            $candidates[] = [
                'job_analysis_finding_id' => $finding->id,
                'term' => $term,
                'prompt' => "The job asks about {$term}. Your documented career history doesn't show direct experience with it — have you used it in a role or project?",
                'tier' => $tier,
            ];
        }

        usort($candidates, fn ($a, $b) => $a['tier'] <=> $b['tier']);

        return array_slice($candidates, 0, self::MAX_CANDIDATES);
    }

    /**
     * Lower is higher priority: required+high (1) > required+normal (2)
     * > preferred+high (3). Anything else does not qualify at all —
     * `not_assessable` findings are excluded upstream (a `no_evidence`
     * JobMatchFinding never coexists with `not_assessable`, but a
     * finding whose requirement_strength/emphasis doesn't clear this
     * bar is simply not a candidate).
     */
    private function tier(?JobAnalysisRequirementStrength $requirementStrength, ?JobAnalysisEmphasis $emphasis): ?int
    {
        $isRequired = $requirementStrength === JobAnalysisRequirementStrength::Required;
        $isPreferred = $requirementStrength === JobAnalysisRequirementStrength::Preferred;
        $isHighEmphasis = $emphasis === JobAnalysisEmphasis::High;

        return match (true) {
            $isRequired && $isHighEmphasis => 1,
            $isRequired => 2,
            $isPreferred && $isHighEmphasis => 3,
            default => null,
        };
    }
}
