<?php

namespace App\Support\ApplicationInspection;

use App\Models\WorkerHeartbeat;

/**
 * The one safe JSON shape Career Toolkit ever hands a browser for
 * worker presence — used by both
 * App\Http\Controllers\BrowserWorkerStatusController (the persistent
 * nav badge) and anywhere else "is inspection capacity available"
 * needs answering. We only have one worker today, so this reports the
 * single most-recently-seen App\Models\WorkerHeartbeat row rather than
 * a list — revisit only if a second concurrent worker identity becomes
 * real. See docs/application-inspector.md "Worker presence".
 */
final class PresentWorkerAvailability
{
    /**
     * @return array<string, mixed>
     */
    public function present(): array
    {
        $heartbeat = WorkerHeartbeat::query()->latest('last_seen_at')->first();

        if ($heartbeat === null) {
            return [
                'identity' => null,
                'worker_type' => null,
                'online' => false,
                'last_seen_at' => null,
            ];
        }

        return [
            'identity' => $heartbeat->identity,
            'worker_type' => $heartbeat->worker_type,
            'online' => $this->isOnline($heartbeat),
            'last_seen_at' => $heartbeat->last_seen_at->toIso8601String(),
        ];
    }

    private function isOnline(WorkerHeartbeat $heartbeat): bool
    {
        $pollIntervalSeconds = (int) config('services.browser_worker.poll_interval_seconds');

        // 3x the worker's own expected poll interval — comfortably
        // survives a single missed/slow poll without flapping, tight
        // enough that "online" still means "recently proven reachable".
        // Falls inside the 15-20s example range at the documented
        // default (5s -> 15s).
        $onlineThresholdSeconds = $pollIntervalSeconds * 3;

        return $heartbeat->last_seen_at->greaterThan(now()->subSeconds($onlineThresholdSeconds));
    }
}
