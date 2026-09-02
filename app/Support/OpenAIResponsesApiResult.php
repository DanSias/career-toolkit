<?php

namespace App\Support;

/**
 * The successful, decoded result of one OpenAI Responses API structured-
 * output call — provider-agnostic-within-OpenAI, shared by
 * OpenAIJobAnalysisClient and OpenAIJobMatchClient via
 * OpenAIResponsesApiClient. Neither domain-specific *ProviderResponse
 * DTO (JobAnalysisProviderResponse, JobMatchProviderResponse) is built
 * here — each concrete client wraps this into its own.
 */
final readonly class OpenAIResponsesApiResult
{
    /**
     * @param  array<string, mixed>  $structuredContent
     */
    public function __construct(
        public string $model,
        public array $structuredContent,
    ) {}
}
