<?php

namespace Database\Factories;

use App\Enums\SkillCategory;
use App\Models\CareerProfile;
use App\Models\Skill;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Skill>
 */
class SkillFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->word();

        return [
            'career_profile_id' => CareerProfile::factory(),
            'name' => $name,
            'slug' => Str::slug($name),
            'category' => fake()->randomElement(SkillCategory::cases()),
            'description' => null,
        ];
    }
}
