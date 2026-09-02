<?php

namespace App\Support\JobAnalysis;

use App\Enums\JobAnalysisSeniority;

/**
 * The complete, trusted result of one generation attempt: an
 * already-validated, already evidence-verified provider response, ready
 * to be persisted as one immutable JobAnalysis snapshot. Not an
 * Eloquent model — see GenerateJobAnalysis, which is the only place
 * this is ever turned into database rows.
 */
final readonly class JobAnalysisDraft
{
    /**
     * @param  array<int, JobAnalysisFindingDraft>  $findings
     */
    public function __construct(
        public string $roleSummary,
        public JobAnalysisSeniority $overallSeniority,
        public string $seniorityRationale,
        public array $findings,
    ) {}
}
