<?php

namespace App\Support\JobMatch;

use App\Enums\JobMatchCoverage;

/**
 * One finding's complete matching result from an already-validated
 * provider response. Not an Eloquent model — see GenerateJobMatch for
 * where this is finally persisted as a JobMatchFinding plus its
 * CareerFactMatch/EducationMatch children.
 */
final readonly class JobMatchFindingDraft
{
    /**
     * @param  array<int, CareerFactMatchDraft>  $careerFactMatches
     * @param  array<int, EducationMatchDraft>  $educationMatches
     */
    public function __construct(
        public int $jobAnalysisFindingId,
        public JobMatchCoverage $coverage,
        public ?string $coverageRationale,
        public array $careerFactMatches,
        public array $educationMatches,
    ) {}
}
