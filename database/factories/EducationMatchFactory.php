<?php

namespace Database\Factories;

use App\Enums\MatchRelationship;
use App\Models\Education;
use App\Models\EducationMatch;
use App\Models\JobMatchFinding;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EducationMatch>
 */
class EducationMatchFactory extends Factory
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
            'education_id' => Education::factory(),
            'relationship' => MatchRelationship::Direct,
            'rationale' => fake()->sentence(),
        ];
    }
}
