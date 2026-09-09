<?php

namespace Database\Factories;

use App\Models\CareerFact;
use App\Models\ResumeVariantProjectBullet;
use App\Models\ResumeVariantProjectBulletCitation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ResumeVariantProjectBulletCitation>
 */
class ResumeVariantProjectBulletCitationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'resume_variant_project_bullet_id' => ResumeVariantProjectBullet::factory(),
            'career_fact_id' => CareerFact::factory(),
        ];
    }
}
