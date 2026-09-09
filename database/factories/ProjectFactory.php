<?php

namespace Database\Factories;

use App\Models\CareerProfile;
use App\Models\Project;
use App\Models\Role;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Project>
 */
class ProjectFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = Str::title(rtrim(fake()->unique()->sentence(3), '.'));

        return [
            'role_id' => Role::factory(),
            'name' => $name,
            'slug' => Str::slug($name).'-'.fake()->unique()->numberBetween(1, 999999),
            'description' => null,
            'default_visibility' => null,
            'sort_order' => 0,
        ];
    }

    /**
     * An independent/personal Project — no Role at all, owned directly
     * by a CareerProfile. See docs/domain-model.md "Project ownership".
     * career_profile_id must be supplied explicitly here (never
     * auto-derived) since there is no Role to derive it from; pass
     * ->for($profile) to target a specific one.
     */
    public function independent(): static
    {
        return $this->state(fn () => [
            'role_id' => null,
            'career_profile_id' => CareerProfile::factory(),
        ]);
    }
}
