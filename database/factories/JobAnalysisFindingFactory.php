<?php

namespace Database\Factories;

use App\Enums\JobAnalysisEmphasis;
use App\Enums\JobAnalysisFindingBasis;
use App\Enums\JobAnalysisFindingCategory;
use App\Enums\JobAnalysisRequirementStrength;
use App\Models\JobAnalysis;
use App\Models\JobAnalysisFinding;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<JobAnalysisFinding>
 */
class JobAnalysisFindingFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'job_analysis_id' => JobAnalysis::factory(),
            'category' => JobAnalysisFindingCategory::RequiredQualification,
            'statement' => fake()->sentence(),
            'label' => null,
            'basis' => JobAnalysisFindingBasis::Explicit,
            'requirement_strength' => JobAnalysisRequirementStrength::Required,
            'emphasis' => JobAnalysisEmphasis::Normal,
            'maturity' => null,
            'years_experience_min' => null,
            'years_experience_max' => null,
            'recency_requirement' => null,
            'time_horizon' => null,
            'notes' => null,
        ];
    }

    public function notRequired(): static
    {
        return $this->state(fn () => [
            'requirement_strength' => JobAnalysisRequirementStrength::NotRequired,
        ]);
    }

    public function ambiguousStrength(): static
    {
        return $this->state(fn () => [
            'requirement_strength' => null,
        ]);
    }

    public function experienceFloor(float $min): static
    {
        return $this->state(fn () => [
            'category' => JobAnalysisFindingCategory::RequiredQualification,
            'years_experience_min' => $min,
            'years_experience_max' => null,
        ]);
    }

    public function experienceRange(float $min, float $max): static
    {
        return $this->state(fn () => [
            'category' => JobAnalysisFindingCategory::RequiredQualification,
            'years_experience_min' => $min,
            'years_experience_max' => $max,
        ]);
    }

    public function inferred(): static
    {
        return $this->state(fn () => [
            'basis' => JobAnalysisFindingBasis::Inferred,
        ]);
    }

    public function highEmphasis(): static
    {
        return $this->state(fn () => [
            'category' => JobAnalysisFindingCategory::Travel,
            'emphasis' => JobAnalysisEmphasis::High,
        ]);
    }
}
