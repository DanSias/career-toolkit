<?php

namespace Database\Factories;

use App\Enums\DiscoveryStatus;
use App\Enums\JobDiscoverySource;
use App\Models\DiscoveryProviderAttempt;
use App\Models\DiscoveryRun;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DiscoveryProviderAttempt>
 */
class DiscoveryProviderAttemptFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'discovery_run_id' => DiscoveryRun::factory(),
            'provider' => JobDiscoverySource::Himalayas,
            'status' => DiscoveryStatus::Succeeded,
            'started_at' => now(),
            'finished_at' => now(),
        ];
    }
}
