<?php

namespace App\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Thrown by App\Support\ResumeDocument\ResumePdfValidator when the
 * supplied bytes cannot be parsed as a PDF at all (malformed, empty, or
 * otherwise not a well-formed document) — distinct from
 * ResumePdfPageBudgetExceededException, which is for a validly-parsed
 * PDF that simply has too many pages. Never treat unparseable input as
 * acceptable by default; this makes that failure explicit.
 */
final class InvalidResumePdfException extends RuntimeException
{
    public function __construct(string $message, ?Throwable $previous = null)
    {
        parent::__construct($message, previous: $previous);
    }
}
