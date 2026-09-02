<?php

namespace App\Support\JobMatch;

use App\Models\JobAnalysis;
use App\Models\JobAnalysisFinding;

/**
 * Builds the normalized job-side payload for one JobAnalysis — the
 * role-level context plus every JobAnalysisFinding, reduced to exactly
 * the approved matcher-facing fields. Deliberately excludes
 * JobAnalysisFindingEvidence (the posting excerpts): a finding's own
 * `statement` is already the trusted, normalized claim those excerpts
 * support — JobAnalysis has already done that extraction. See
 * docs/job-match-contract.md "Job input contract".
 */
final class JobPayloadBuilder
{
    /**
     * @return array<string, mixed>
     */
    public function build(JobAnalysis $analysis): array
    {
        return [
            'role_summary' => $analysis->role_summary,
            'overall_seniority' => $analysis->overall_seniority?->value,
            'seniority_rationale' => $analysis->seniority_rationale,
            'findings' => $analysis->findings()
                ->orderBy('id')
                ->get()
                ->map(fn (JobAnalysisFinding $finding) => $this->normalizeFinding($finding))
                ->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeFinding(JobAnalysisFinding $finding): array
    {
        return [
            'id' => $finding->id,
            'category' => $finding->category->value,
            'statement' => $finding->statement,
            'label' => $finding->label,
            'basis' => $finding->basis->value,
            'requirement_strength' => $finding->requirement_strength?->value,
            'emphasis' => $finding->emphasis?->value,
            'maturity' => $finding->maturity?->value,
            'years_experience_min' => $finding->years_experience_min,
            'years_experience_max' => $finding->years_experience_max,
            'recency_requirement' => $finding->recency_requirement,
            'time_horizon' => $finding->time_horizon,
            'notes' => $finding->notes,
        ];
    }
}
