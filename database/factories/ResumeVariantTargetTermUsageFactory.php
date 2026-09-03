<?php

namespace Database\Factories;

use App\Enums\ResumeClaimPosture;
use App\Enums\ResumeQualifiedPhrase;
use App\Enums\ResumeTermUsageLocation;
use App\Models\JobAnalysisFinding;
use App\Models\ResumeVariant;
use App\Models\ResumeVariantTargetTermUsage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ResumeVariantTargetTermUsage>
 */
class ResumeVariantTargetTermUsageFactory extends Factory
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
            'bullet_id' => null,
            'job_analysis_finding_id' => JobAnalysisFinding::factory(),
            'target_term' => 'Azure',
            'posture' => ResumeClaimPosture::Capability,
            'location' => ResumeTermUsageLocation::Summary,
            'relationship_phrase_key' => ResumeQualifiedPhrase::NotApplicable,
        ];
    }

    public function qualified(): static
    {
        return $this->state(fn () => [
            'posture' => ResumeClaimPosture::Qualified,
            'relationship_phrase_key' => ResumeQualifiedPhrase::ApplicableTo,
        ]);
    }

    public function direct(): static
    {
        return $this->state(fn () => [
            'posture' => ResumeClaimPosture::Direct,
            'relationship_phrase_key' => ResumeQualifiedPhrase::NotApplicable,
        ]);
    }
}
