<?php

namespace Database\Factories;

use App\Models\Education;
use App\Models\ResumeVariant;
use App\Models\ResumeVariantEducationSelection;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ResumeVariantEducationSelection>
 */
class ResumeVariantEducationSelectionFactory extends Factory
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
            'education_id' => Education::factory(),
            'institution' => fake()->company(),
            'degree' => 'Bachelor of Science',
            'field_of_study' => fake()->word(),
            'start_year' => null,
            'end_year' => fake()->year(),
            'display_order' => 1,
        ];
    }
}
