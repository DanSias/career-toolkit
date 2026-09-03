<?php

namespace Database\Factories;

use App\Models\ResumeVariant;
use App\Models\ResumeVariantSkillSelection;
use App\Models\Skill;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ResumeVariantSkillSelection>
 */
class ResumeVariantSkillSelectionFactory extends Factory
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
            'skill_id' => Skill::factory(),
            'display_order' => 1,
        ];
    }
}
