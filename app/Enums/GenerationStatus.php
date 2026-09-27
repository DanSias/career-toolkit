<?php

namespace App\Enums;

/**
 * The durable lifecycle of a GenerationAttempt. Always progresses
 * forward — Queued -> Running -> (Succeeded | Failed) — never backward,
 * and a row is never reused across lifecycles: a retry is always a new
 * GenerationAttempt row, mirroring the immutable-snapshot convention
 * already used by JobAnalysis/JobMatch/ResumeVariant themselves.
 */
enum GenerationStatus: string
{
    case Queued = 'queued';
    case Running = 'running';
    case Succeeded = 'succeeded';
    case Failed = 'failed';

    /**
     * Queued or Running — an attempt that is not yet finished, and
     * therefore the one a "does this subject+stage already have work
     * in flight" check should find. See
     * GenerationAttempt::scopeActive().
     *
     * @return array<int, self>
     */
    public static function active(): array
    {
        return [self::Queued, self::Running];
    }
}
