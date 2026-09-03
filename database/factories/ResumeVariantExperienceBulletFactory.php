<?php

namespace Database\Factories;

use App\Models\Employer;
use App\Models\ResumeVariant;
use App\Models\ResumeVariantExperienceBullet;
use App\Models\Role;
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
            'employer_id' => Employer::factory(),
            'role_id' => Role::factory(),
            'project_id' => null,
            'display_title' => fake()->jobTitle(),
            'display_order' => 1,
            'text' => fake()->sentence(),
        ];
    }
}
