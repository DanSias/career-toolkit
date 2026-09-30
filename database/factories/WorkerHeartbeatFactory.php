<?php

namespace Database\Factories;

use App\Models\WorkerHeartbeat;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WorkerHeartbeat>
 */
class WorkerHeartbeatFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'identity' => 'ai-box-browser-inspector',
            'worker_type' => 'browser_inspector',
            'last_seen_at' => now(),
        ];
    }
}
