<?php

namespace App\Exceptions;

use App\Support\ProviderDiagnostics;
use RuntimeException;
use Throwable;

/**
 * Thrown when the JobAnalysis model provider itself fails — a transport
 * error, an exhausted retry budget, a non-2xx response, or a response
 * whose content isn't decodable JSON at all. Distinct from
 * InvalidJobAnalysisResponseException, which covers a well-formed reply
 * that fails our own schema/business-rule validation. Never carries
 * credentials or raw request/response headers in its message. See
 * docs/job-analysis-generation.md.
 *
 * `$diagnostics` is optional, non-content provider metadata (model,
 * finish_reason, token usage, configured budget/timeout) — populated
 * when the underlying transport exception could supply it (currently
 * OllamaChatCompletionsException; OpenAIResponsesApiException carries
 * no equivalent fields yet, so that path simply omits it). Lets a
 * caller (e.g. the controller's failure log) report exactly why/how a
 * provider call failed — a truncation at the configured output-token
 * ceiling versus a connection failure look identical in the exception
 * message's class alone — without reaching through `getPrevious()`.
 */
class JobAnalysisProviderException extends RuntimeException
{
    public function __construct(string $message, public readonly ?ProviderDiagnostics $diagnostics = null, ?Throwable $previous = null)
    {
        parent::__construct($message, previous: $previous);
    }
}
