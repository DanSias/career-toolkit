<?php

namespace App\Enums;

/**
 * The durable technical-execution lifecycle of one AgentRun — the same
 * four-case shape as App\Enums\GenerationStatus, deliberately a
 * distinct enum class rather than a reuse of it: GenerationAttempt
 * remains specialized, and nothing about AgentRun should reach into
 * its enum. Always progresses forward, never backward; a retry is
 * always a new AgentRun, never a reopened one.
 *
 * Deliberately orthogonal to inspection outcome (see
 * App\Enums\InspectionOutcome) — this enum answers only "did the
 * browser execution itself complete without error." See
 * docs/domain-model.md "WorkflowRun, WorkflowStep, and AgentRun".
 */
enum AgentRunStatus: string
{
    case Queued = 'queued';
    case Running = 'running';
    case Succeeded = 'succeeded';
    case Failed = 'failed';

    /**
     * Queued or Running — an attempt that is not yet finished.
     *
     * @return array<int, self>
     */
    public static function active(): array
    {
        return [self::Queued, self::Running];
    }
}
