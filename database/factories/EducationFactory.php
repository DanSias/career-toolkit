<?php

namespace Database\Factories;

use App\Models\CareerProfile;
use App\Models\Education;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Education>
 */
class EducationFactory extends Factory
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
            'institution' => fake()->company().' University',
            'degree' => fake()->randomElement(['Bachelor of Science', 'Master of Science']),
            'field_of_study' => fake()->word(),
            'start_year' => null,
            'end_year' => fake()->numberBetween(1990, 2020),
            'sort_order' => 0,
        ];
    }
}
