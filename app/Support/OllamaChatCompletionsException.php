<?php

namespace App\Support;

use RuntimeException;

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
 */
class OllamaChatCompletionsException extends RuntimeException {}
