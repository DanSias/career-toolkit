<?php

namespace Database\Factories;

use App\Models\ResumeVariantProject;
use App\Models\ResumeVariantProjectBullet;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ResumeVariantProjectBullet>
 */
class ResumeVariantProjectBulletFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'resume_variant_project_id' => ResumeVariantProject::factory(),
            'display_order' => 1,
            'text' => fake()->sentence(),
        ];
    }
}
