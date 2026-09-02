<?php

namespace Database\Factories;

use App\Models\Employer;
use App\Models\Role;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Role>
 */
class RoleFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'employer_id' => Employer::factory(),
            'title' => fake()->jobTitle(),
            'start_date' => fake()->dateTimeBetween('-10 years', '-2 years'),
            'end_date' => null,
            'summary' => null,
            'sort_order' => 0,
        ];
    }

    /**
     * Give the role a closed date range instead of being current.
     */
    public function ended(): static
    {
        return $this->state(fn (array $attributes) => [
            'end_date' => fake()->dateTimeBetween($attributes['start_date'] ?? '-2 years', 'now'),
        ]);
    }
}
