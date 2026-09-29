<?php

namespace App\Enums;

/**
 * Why an AgentRun ended Failed — mirrors GenerationAttempt's own
 * failure_category convention (see App\Models\GenerationAttempt),
 * applied to browser-worker execution instead of LLM generation.
 *
 * - ClaimTimeout — a worker claimed the run and never reported back
 *   within claim_expires_at (detected by Career Toolkit itself, not the
 *   worker).
 * - NavigationTimeout — the worker's own page-load/navigation attempt
 *   timed out.
 * - UnsupportedAts — the worker could not identify a supported ATS.
 * - UnexpectedError — anything else, self-reported by the worker or
 *   caught by Career Toolkit's own failure handling.
 */
enum AgentRunFailureCategory: string
{
    case ClaimTimeout = 'claim_timeout';
    case NavigationTimeout = 'navigation_timeout';
    case UnsupportedAts = 'unsupported_ats';
    case UnexpectedError = 'unexpected_error';
}
