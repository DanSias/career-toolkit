<?php

namespace Database\Factories;

use App\Enums\JobCanonicalSource;
use App\Models\KnownAtsBoard;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<KnownAtsBoard>
 */
class KnownAtsBoardFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_name' => fake()->unique()->company(),
            'ats_type' => fake()->randomElement(JobCanonicalSource::cases()),
            'board_identifier' => fake()->slug(),
        ];
    }
}
