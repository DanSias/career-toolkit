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
