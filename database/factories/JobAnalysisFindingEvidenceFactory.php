<?php

namespace Database\Factories;

use App\Models\JobAnalysisFinding;
use App\Models\JobAnalysisFindingEvidence;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<JobAnalysisFindingEvidence>
 */
class JobAnalysisFindingEvidenceFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'job_analysis_finding_id' => JobAnalysisFinding::factory(),
            'excerpt' => fake()->sentence(),
            'source_section' => 'Requirements',
            'source_locator' => null,
        ];
    }
}
