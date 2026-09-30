<?php

namespace App\Support\ApplicationInspection;

use App\Models\WorkerHeartbeat;

/**
 * Piggybacks worker presence on the existing claim-poll protocol
 * traffic rather than adding a dedicated heartbeat endpoint — a
 * successful, authenticated claim poll (work found or not) already
 * proves the worker can reach Career Toolkit. Called from
 * App\Http\Controllers\Worker\AgentRunClaimController::store(), after
 * App\Http\Middleware\AuthenticateBrowserWorker has already rejected
 * any unauthenticated/invalid request — so an invalid token never
 * reaches here and never updates presence. See
 * docs/application-inspector.md "Worker presence".
 */
final class RecordWorkerHeartbeat
{
    public function record(string $identity, string $workerType): void
    {
        WorkerHeartbeat::updateOrCreate(
            ['identity' => $identity],
            ['worker_type' => $workerType, 'last_seen_at' => now()],
        );
    }
}
