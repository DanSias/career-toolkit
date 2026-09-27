<?php

namespace App\Exceptions;

use App\Support\ProviderDiagnostics;
use RuntimeException;
use Throwable;

/**
 * Thrown when either resume-generation model provider fails — a
 * transport error, an exhausted retry budget, a non-2xx response, or a
 * response whose content isn't decodable JSON at all. Shared by both
 * OpenAIResumeSelectionClient and OpenAIResumeWordingClient: the two
 * stages are separate calls with separate DTOs and separate schemas,
 * but a transport-level failure means the same thing for either
 * ("nothing persisted, log a warning, the caller retries"), so one
 * exception type is enough rather than two near-duplicates. Distinct
 * from InvalidResumeVariantResponseException, which covers a
 * well-formed reply that fails our own validation. Never carries
 * credentials or raw request/response headers in its message.
 *
 * `$diagnostics` — see JobAnalysisProviderException's own docblock for
 * what this optional, non-content metadata is and why it exists.
 */
class ResumeGenerationProviderException extends RuntimeException
{
    public function __construct(string $message, public readonly ?ProviderDiagnostics $diagnostics = null, ?Throwable $previous = null)
    {
        parent::__construct($message, previous: $previous);
    }
}
