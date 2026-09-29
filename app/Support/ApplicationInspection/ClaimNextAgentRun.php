<?php

namespace App\Support\ApplicationInspection;

use App\Enums\AgentRunFailureCategory;
use App\Enums\AgentRunStatus;
use App\Enums\WorkflowStatus;
use App\Models\AgentRun;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The worker-facing claim protocol's implementation. Two concerns,
 * both keyed off the same atomic-conditional-update pattern (never a
 * plain SELECT-then-UPDATE): recovering any stale claim left behind by
 * a worker that disappeared mid-inspection, then claiming exactly one
 * queued browser_inspector AgentRun for the polling worker. See
 * docs/application-inspector.md "Claim lease / crash recovery".
 *
 * Safe under concurrent pollers on this app's actual database
 * (SQLite): write transactions serialize at the engine level, and each
 * mutating query here is additionally gated by a WHERE clause on the
 * row's expected current status, checked via affected-row-count — so
 * even without SQLite's own serialization, only one of two racing
 * conditional updates against the same row could ever succeed.
 */
final class ClaimNextAgentRun
{
    public function claim(string $workerIdentity): ?AgentRun
    {
        $this->recoverExpiredClaims();

        return DB::transaction(function () use ($workerIdentity) {
            $candidate = AgentRun::query()
                ->where('agent_type', 'browser_inspector')
                ->where('status', AgentRunStatus::Queued->value)
                ->oldest('id')
                ->first();

            if ($candidate === null) {
                return null;
            }

            $now = now();
            $leaseSeconds = (int) config('services.browser_worker.claim_ttl');

            $affected = AgentRun::query()
                ->where('id', $candidate->id)
                ->where('status', AgentRunStatus::Queued->value)
                ->update([
                    'status' => AgentRunStatus::Running->value,
                    'worker_identity' => $workerIdentity,
                    'started_at' => $now,
                    'claim_expires_at' => $now->addSeconds($leaseSeconds),
                ]);

            if ($affected === 0) {
                // Lost the race to another concurrent poll.
                return null;
            }

            $agentRun = $candidate->fresh();
            $this->markRunning($agentRun, $now);

            return $agentRun;
        });
    }

    private function markRunning(AgentRun $agentRun, CarbonImmutable $now): void
    {
        $step = $agentRun->workflowStep;

        if ($step->status !== WorkflowStatus::Running) {
            $step->update([
                'status' => WorkflowStatus::Running,
                'started_at' => $step->started_at ?? $now,
            ]);
        }

        $run = $step->workflowRun;

        if ($run->status !== WorkflowStatus::Running) {
            $run->update([
                'status' => WorkflowStatus::Running,
                'started_at' => $run->started_at ?? $now,
            ]);
        }
    }

    /**
     * A worker that claimed an AgentRun and never reported back leaves
     * it Running forever unless something notices. Career Toolkit
     * notices lazily, here, on the next real claim poll — no
     * scheduler/watchdog process exists solely for this. See
     * docs/application-inspector.md "Retry semantics": the expired
     * attempt is marked Failed, never reopened or retried
     * automatically; a human retries via a brand new WorkflowRun.
     */
    private function recoverExpiredClaims(): void
    {
        $expired = AgentRun::query()
            ->where('agent_type', 'browser_inspector')
            ->where('status', AgentRunStatus::Running->value)
            ->where('claim_expires_at', '<', now())
            ->get();

        foreach ($expired as $agentRun) {
            $this->recoverOne($agentRun);
        }
    }

    private function recoverOne(AgentRun $agentRun): void
    {
        DB::transaction(function () use ($agentRun) {
            $now = now();

            $affected = AgentRun::query()
                ->where('id', $agentRun->id)
                ->where('status', AgentRunStatus::Running->value)
                ->update([
                    'status' => AgentRunStatus::Failed->value,
                    'failure_category' => AgentRunFailureCategory::ClaimTimeout->value,
                    'failure_message' => 'Worker claim expired before a result was reported.',
                    'finished_at' => $now,
                ]);

            if ($affected === 0) {
                // A concurrent result POST or another recovery pass
                // already resolved this one — nothing left to do.
                return;
            }

            $step = $agentRun->workflowStep;

            if ($step->status === WorkflowStatus::Running) {
                $step->update([
                    'status' => WorkflowStatus::Failed,
                    'failure_message' => 'Inspection worker claim expired.',
                    'finished_at' => $now,
                ]);
            }

            $run = $step->workflowRun;

            if ($run->status === WorkflowStatus::Running) {
                $run->update([
                    'status' => WorkflowStatus::Failed,
                    'failure_message' => 'Inspection worker claim expired.',
                    'finished_at' => $now,
                ]);
            }
        });
    }
}
