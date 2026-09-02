<?php

namespace App\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Thrown when a provider's decoded JobMatch response fails deterministic
 * application-side validation — malformed structure, an unsupported
 * enum value, incomplete finding coverage, a reference to a
 * JobAnalysisFinding/CareerFact/Education not supplied in the request,
 * a duplicate reference, or an impossible coverage state (e.g.
 * `no_evidence` with a non-empty match list). In every case, matching
 * fails as a whole and nothing is persisted. Mirrors
 * InvalidJobAnalysisResponseException's role. See
 * docs/job-match-generation.md.
 *
 * Carries the same optional structured `$context` for diagnosis that
 * InvalidJobAnalysisResponseException does — never logged automatically,
 * available for callers that deliberately want to inspect it.
 */
class InvalidJobMatchResponseException extends RuntimeException
{
    /**
     * @param  array<string, mixed>|null  $context
     */
    public function __construct(string $message, public readonly ?array $context = null, ?Throwable $previous = null)
    {
        parent::__construct($message, previous: $previous);
    }
}
