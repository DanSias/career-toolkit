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
            'start_year' => fake()->numberBetween(2015, 2023),
            'start_month' => fake()->numberBetween(1, 12),
            'end_year' => null,
            'end_month' => null,
            'summary' => null,
            'sort_order' => 0,
        ];
    }

    /**
     * Give the role a closed date range instead of being current.
     */
    public function ended(): static
    {
        return $this->state(function (array $attributes) {
            $startYear = $attributes['start_year'] ?? 2020;

            // At least a year later, so end_month can never precede
            // start_month within the same year regardless of what it
            // rolls to.
            $endYear = $startYear + fake()->numberBetween(1, 3);

            return [
                'end_year' => $endYear,
                'end_month' => fake()->numberBetween(1, 12),
            ];
        });
    }

    /**
     * Give the role year-only precision (no month evidenced), on both
     * ends when the role is also `ended()`.
     */
    public function yearOnly(): static
    {
        return $this->state(fn () => [
            'start_month' => null,
            'end_month' => null,
        ]);
    }
}
