<?php

namespace Database\Factories;

use App\Models\ResumeVariant;
use App\Models\ResumeVariantExperienceBullet;
use App\Models\ResumeVariantExperienceRole;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ResumeVariantExperienceBullet>
 */
class ResumeVariantExperienceBulletFactory extends Factory
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
            'resume_variant_experience_role_id' => ResumeVariantExperienceRole::factory(),
            'project_id' => null,
            'display_order' => 1,
            'text' => fake()->sentence(),
        ];
    }
}
