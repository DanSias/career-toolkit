<?php

namespace Database\Factories;

use App\Models\CareerProfile;
use App\Models\JobAnalysis;
use App\Models\JobMatch;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<JobMatch>
 */
class JobMatchFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'job_analysis_id' => JobAnalysis::factory(),
            'career_profile_id' => CareerProfile::factory(),
            'schema_version' => '1.0',
            'prompt_version' => 'job-match-v1',
            'generated_by' => 'test-fixture',
            'generated_at' => now(),
            'input_snapshot' => ['candidate' => ['career_facts' => [], 'education' => []], 'job' => ['findings' => []]],
            'raw_response' => null,
        ];
    }
}
