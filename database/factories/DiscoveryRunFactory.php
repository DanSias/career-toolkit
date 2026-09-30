<?php

namespace Database\Factories;

use App\Enums\DiscoveryStatus;
use App\Models\DiscoveryRun;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DiscoveryRun>
 */
class DiscoveryRunFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'status' => DiscoveryStatus::Succeeded,
            'started_at' => now(),
            'finished_at' => now(),
        ];
    }
}
