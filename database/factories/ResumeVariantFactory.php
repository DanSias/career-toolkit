<?php

namespace Database\Factories;

use App\Models\CareerProfile;
use App\Models\JobMatch;
use App\Models\ResumeVariant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ResumeVariant>
 */
class ResumeVariantFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'career_profile_id' => CareerProfile::factory(),
            'job_match_id' => JobMatch::factory(),
            'schema_version' => '1.0',
            'selection_prompt_version' => 'resume-selection-v1',
            'wording_prompt_version' => 'resume-wording-v1',
            'selection_generated_by' => 'test-fixture',
            'wording_generated_by' => 'test-fixture',
            'generated_at' => now(),
            'selection_input_snapshot' => ['career_facts' => [], 'education' => [], 'eligible_skills' => [], 'job' => [], 'target_terminology' => []],
            'selection_raw_response' => null,
            'wording_input_snapshot' => ['selection' => [], 'job' => []],
            'wording_raw_response' => null,
            'summary' => fake()->sentence(),
        ];
    }
}
