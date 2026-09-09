<?php

namespace Database\Factories;

use App\Models\Project;
use App\Models\ResumeVariant;
use App\Models\ResumeVariantProject;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ResumeVariantProject>
 */
class ResumeVariantProjectFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'resume_variant_id' => ResumeVariant::factory(),
            'project_id' => Project::factory()->independent(),
            'name' => fake()->words(3, true),
            'technology_names' => [],
            'live_url' => null,
            'repository_url' => null,
            'display_order' => 1,
        ];
    }
}
