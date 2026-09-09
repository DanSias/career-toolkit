<?php

namespace Database\Factories;

use App\Models\ResumeVariant;
use App\Models\ResumeVariantExperienceRole;
use App\Models\Role;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ResumeVariantExperienceRole>
 */
class ResumeVariantExperienceRoleFactory extends Factory
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
            'role_id' => Role::factory(),
            'employer_name' => fake()->company(),
            'display_title' => fake()->jobTitle(),
            'start_year' => fake()->numberBetween(2015, 2024),
            'start_month' => fake()->numberBetween(1, 12),
            'end_year' => null,
            'end_month' => null,
            'display_order' => 1,
        ];
    }
}
