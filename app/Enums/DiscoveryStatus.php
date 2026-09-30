<?php

namespace App\Enums;

/**
 * The durable lifecycle shared by DiscoveryRun and
 * DiscoveryProviderAttempt — inspired by
 * App\Enums\GenerationStatus, deliberately a distinct enum class
 * rather than a reuse of it, mirroring AgentRunStatus's own precedent:
 * discovery execution is its own concern and nothing about it should
 * reach into GenerationAttempt's enum. Partial is a run-only outcome when some work succeeds. Always progresses forward,
 * never backward.
 */
enum DiscoveryStatus: string
{
    case Queued = 'queued';
    case Running = 'running';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Partial = 'partial';
}
