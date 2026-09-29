<?php

namespace App\Enums;

/**
 * The durable lifecycle shared by WorkflowRun and WorkflowStep —
 * deliberately one enum reused across both models, since a step's own
 * state machine is structurally identical to a run's, just at finer
 * grain, and two separately-defined enums with the same cases would
 * only invite drift. Always progresses forward, never backward; a
 * retry is always a new WorkflowRun/WorkflowStep, mirroring
 * GenerationAttempt's own "a retry is always a new row" convention.
 *
 * No Waiting case in this milestone: nothing in the current pipeline
 * pauses, so the supporting columns a real waiting state would need (a
 * wait reason, a pointer to what's being waited on) don't exist yet
 * either. Cancelled has no producer yet either, but costs nothing extra
 * to support now. See docs/domain-model.md "WorkflowRun, WorkflowStep,
 * and AgentRun".
 */
enum WorkflowStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    /**
     * Pending or Running — a run/step that is not yet finished.
     *
     * @return array<int, self>
     */
    public static function active(): array
    {
        return [self::Pending, self::Running];
    }
}
