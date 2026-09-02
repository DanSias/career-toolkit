<?php

namespace App\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Thrown when a provider's decoded JobAnalysis response fails
 * deterministic application-side validation — malformed structure,
 * an unsupported enum value, empty findings, an invalid experience
 * range, or (per the strict v1 policy) any evidence excerpt that
 * cannot be verified against the source JobPosting's description. In
 * every case, generation fails as a whole and nothing is persisted.
 * See docs/job-analysis-generation.md.
 *
 * `getMessage()` stays a short, safe description — it's what
 * JobAnalysisController logs at warning level today, and that call
 * site is deliberately not being widened to log more by default.
 * `$context` carries the specific diagnostic data (e.g., for an
 * evidence-verification failure: finding/evidence index, the offending
 * excerpt, and the owning finding) for callers that deliberately want
 * to inspect it — targeted debugging, or the opt-in live-corpus
 * evaluation in tests/Llm. It is never logged automatically anywhere.
 */
class InvalidJobAnalysisResponseException extends RuntimeException
{
    /**
     * @param  array<string, mixed>|null  $context
     */
    public function __construct(string $message, public readonly ?array $context = null, ?Throwable $previous = null)
    {
        parent::__construct($message, previous: $previous);
    }
}
