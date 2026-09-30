<?php

namespace Database\Factories;

use App\Enums\JobDiscoverySource;
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

    /**
     * A discovered (not manually captured) posting from the given
     * provider, with a real discovery_source_id so the (discovery_source,
     * discovery_source_id) uniqueness constraint is naturally satisfied
     * across factory-created rows in the same test.
     */
    public function discovered(JobDiscoverySource $source = JobDiscoverySource::Himalayas): static
    {
        return $this->state(fn () => [
            'discovery_source' => $source,
            'discovery_source_id' => (string) fake()->unique()->numberBetween(100000, 999999),
        ]);
    }
}
