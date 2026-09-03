<?php

namespace App\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Thrown when either provider's decoded structured response fails
 * deterministic validation — shared by both the Selection and Wording
 * stages, since the orchestration method called (generateFull vs. a
 * future regenerateWording) is what differs between them, not the
 * exception taxonomy. Mirrors InvalidJobMatchResponseException.
 *
 * `$context` carries the raw, untrusted, already-rejected structured
 * content both validators decoded before throwing — never logged
 * automatically, never persisted by production code, available only
 * for a caller that deliberately wants to inspect it (the live-eval
 * harness does; see docs/resume-variant-generation.md "Live
 * evaluation"). Populating this in no way changes what causes
 * validation to fail — it is diagnostic context only.
 */
final class InvalidResumeVariantResponseException extends RuntimeException
{
    /**
     * @param  array<string, mixed>|null  $context
     */
    public function __construct(string $message, public readonly ?array $context = null, ?Throwable $previous = null)
    {
        parent::__construct($message, previous: $previous);
    }
}
