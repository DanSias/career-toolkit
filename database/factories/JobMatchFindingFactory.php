<?php

namespace Database\Factories;

use App\Enums\JobMatchCoverage;
use App\Models\JobAnalysisFinding;
use App\Models\JobMatch;
use App\Models\JobMatchFinding;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<JobMatchFinding>
 */
class JobMatchFindingFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'job_match_id' => JobMatch::factory(),
            'job_analysis_finding_id' => JobAnalysisFinding::factory(),
            'coverage' => JobMatchCoverage::Supported,
            'coverage_rationale' => fake()->sentence(),
        ];
    }

    public function noEvidence(): static
    {
        return $this->state(fn () => [
            'coverage' => JobMatchCoverage::NoEvidence,
            'coverage_rationale' => 'No candidate evidence addressing this finding was found in the supplied dataset.',
        ]);
    }

    public function notAssessable(): static
    {
        return $this->state(fn () => [
            'coverage' => JobMatchCoverage::NotAssessable,
            'coverage_rationale' => 'This finding falls outside the career-history and education evidence domain.',
        ]);
    }

    public function partial(): static
    {
        return $this->state(fn () => [
            'coverage' => JobMatchCoverage::Partial,
        ]);
    }
}
