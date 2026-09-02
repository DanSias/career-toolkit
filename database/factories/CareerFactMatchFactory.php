<?php

namespace Database\Factories;

use App\Enums\MatchRelationship;
use App\Models\CareerFact;
use App\Models\CareerFactMatch;
use App\Models\JobMatchFinding;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CareerFactMatch>
 */
class CareerFactMatchFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'job_match_finding_id' => JobMatchFinding::factory(),
            'career_fact_id' => CareerFact::factory(),
            'relationship' => MatchRelationship::Direct,
            'rationale' => fake()->sentence(),
        ];
    }
}
