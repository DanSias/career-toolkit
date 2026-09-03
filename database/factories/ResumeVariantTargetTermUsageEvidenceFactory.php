<?php

namespace Database\Factories;

use App\Models\CareerFact;
use App\Models\ResumeVariantTargetTermUsage;
use App\Models\ResumeVariantTargetTermUsageEvidence;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ResumeVariantTargetTermUsageEvidence>
 */
class ResumeVariantTargetTermUsageEvidenceFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'resume_variant_target_term_usage_id' => ResumeVariantTargetTermUsage::factory(),
            'career_fact_id' => CareerFact::factory(),
        ];
    }
}
