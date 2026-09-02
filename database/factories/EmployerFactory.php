<?php

namespace Database\Factories;

use App\Models\CareerProfile;
use App\Models\Employer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Employer>
 */
class EmployerFactory extends Factory
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
            'name' => fake()->company(),
            'short_name' => null,
            'description' => null,
            'sort_order' => 0,
        ];
    }
}
