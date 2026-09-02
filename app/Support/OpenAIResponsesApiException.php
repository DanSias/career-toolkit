<?php

namespace App\Support;

use RuntimeException;

/**
 * Thrown by OpenAIResponsesApiClient on any transport/provider-level
 * failure — connection error, non-2xx response, incomplete response
 * status, or content that doesn't decode as JSON. Never a domain-facing
 * exception on its own: each concrete client (OpenAIJobAnalysisClient,
 * OpenAIJobMatchClient) catches this and rethrows as its own domain
 * exception (JobAnalysisProviderException, JobMatchProviderException)
 * with the same message, so callers of the public GeneratesJobAnalysis/
 * GeneratesJobMatch interfaces never see this class directly.
 */
class OpenAIResponsesApiException extends RuntimeException {}
