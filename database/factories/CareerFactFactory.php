<?php

namespace Database\Factories;

use App\Enums\CareerFactType;
use App\Enums\Verification;
use App\Enums\Visibility;
use App\Models\CareerFact;
use App\Models\CareerProfile;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<CareerFact>
 */
class CareerFactFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'career_profile_id' => CareerProfile::factory(),
            'key' => Str::slug(fake()->unique()->sentence(4)).'-'.fake()->unique()->numberBetween(1, 999999),
            'fact_type' => CareerFactType::Bullet,
            'statement' => fake()->sentence(),
            'verification' => Verification::NeedsConfirmation,
            'visibility' => Visibility::Restricted,
            'notes' => null,
            'sort_order' => 0,
            // Placeholder — resolved to the fact's own CareerProfile in
            // configure() below, once `career_profile_id` has a real value.
            // Override both attributable_* attributes together to
            // attribute the fact to an Employer, Role, or Project instead.
            'attributable_type' => (new CareerProfile)->getMorphClass(),
            'attributable_id' => 0,
        ];
    }

    /**
     * Default the polymorphic attribution to the fact's own CareerProfile
     * once its `career_profile_id` has been resolved to a real id, unless
     * the caller explicitly set a different attribution target.
     */
    public function configure(): static
    {
        return $this->afterMaking(function (CareerFact $fact) {
            if ($fact->attributable_id === 0 && $fact->attributable_type === (new CareerProfile)->getMorphClass()) {
                $fact->attributable_id = $fact->career_profile_id;
            }
        });
    }
}
