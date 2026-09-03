<?php

namespace Database\Factories;

use App\Models\CareerFact;
use App\Models\ResumeVariantBulletCitation;
use App\Models\ResumeVariantExperienceBullet;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ResumeVariantBulletCitation>
 */
class ResumeVariantBulletCitationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'resume_variant_experience_bullet_id' => ResumeVariantExperienceBullet::factory(),
            'career_fact_id' => CareerFact::factory(),
        ];
    }
}
