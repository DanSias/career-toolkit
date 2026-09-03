<?php

namespace Database\Factories;

use App\Models\CareerFact;
use App\Models\ResumeVariant;
use App\Models\ResumeVariantSummaryEvidence;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ResumeVariantSummaryEvidence>
 */
class ResumeVariantSummaryEvidenceFactory extends Factory
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
            'career_fact_id' => CareerFact::factory(),
        ];
    }
}
