<?php

namespace Database\Factories;

use App\Models\CareerProfile;
use App\Models\JobPosting;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<JobPosting>
 */
class JobPostingFactory extends Factory
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
            'company' => fake()->company(),
            'title' => fake()->jobTitle(),
            'source_url' => fake()->url(),
            'location' => fake()->city(),
            'description' => fake()->paragraphs(5, true),
        ];
    }
}
