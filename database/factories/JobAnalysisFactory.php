<?php

namespace Database\Factories;

use App\Enums\JobAnalysisSeniority;
use App\Models\JobAnalysis;
use App\Models\JobPosting;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<JobAnalysis>
 */
class JobAnalysisFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'job_posting_id' => JobPosting::factory(),
            'schema_version' => '1.0',
            'prompt_version' => 'job-analysis-v1',
            'generated_by' => 'test-fixture',
            'generated_at' => now(),
            'raw_response' => null,
            'role_summary' => fake()->paragraph(),
            'overall_seniority' => JobAnalysisSeniority::Mid,
            'seniority_rationale' => fake()->sentence(),
        ];
    }
}
