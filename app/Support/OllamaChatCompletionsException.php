<?php

namespace App\Support;

use RuntimeException;
use Throwable;

/**
 * Thrown by OllamaChatCompletionsClient on any transport/provider-level
 * failure — no base URL configured, a connection error, a non-2xx
 * response, a truncated/filtered completion, or content that doesn't
 * decode as JSON. Never a domain-facing exception on its own: each
 * concrete client (e.g. OllamaJobAnalysisClient) catches this and
 * rethrows as its own domain exception (e.g.
 * JobAnalysisProviderException) with the same message, so callers of
 * the public Generates* interfaces never see this class directly.
 * Mirrors OpenAIResponsesApiException's role for the OpenAI transport.
 *
 * $model/$finishReason/$usage are diagnostic-only — observability for
 * a failure that already happened, never consulted by any control
 * flow. All three are nullable because they're only populated when
 * OllamaChatCompletionsClient actually had a decoded HTTP response
 * body to read them from before the failure occurred (e.g. a
 * connection error or a non-2xx response never gets this far, so
 * those failure paths leave all three null). A finish_reason:length
 * failure still throws exactly as before — this is metadata attached
 * to that same thrown exception, not a change to whether it throws.
 */
class OllamaChatCompletionsException extends RuntimeException
{
    /**
     * @param  array<string, mixed>|null  $usage
     */
    public function __construct(
        string $message,
        ?Throwable $previous = null,
        public readonly ?string $model = null,
        public readonly ?string $finishReason = null,
        public readonly ?array $usage = null,
    ) {
        parent::__construct($message, previous: $previous);
    }
}
