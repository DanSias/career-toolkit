<?php

namespace App\Exceptions;

use RuntimeException;

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
 */
class ResumeGenerationProviderException extends RuntimeException {}
