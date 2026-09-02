<?php

namespace Database\Factories;

use App\Enums\MetricComparator;
use App\Models\CareerFact;
use App\Models\Metric;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Metric>
 */
class MetricFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'career_fact_id' => CareerFact::factory(),
            'value' => fake()->randomFloat(2, 1, 100),
            'unit' => 'percent_reduction',
            'comparator' => MetricComparator::Exact,
            'scope_note' => null,
            'guardrail' => null,
        ];
    }
}
