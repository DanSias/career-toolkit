<?php

namespace App\Support\ResumeVariant\Providers;

use App\Contracts\GeneratesResumeSelection;
use App\Exceptions\ResumeGenerationProviderException;
use App\Support\OpenAIResponsesApiClient;
use App\Support\OpenAIResponsesApiException;
use App\Support\ResumeVariant\ResumeSelectionProviderResponse;

/**
 * Calls OpenAI's Responses API for Resume Selection generation, using
 * the same native structured-output mechanism as
 * OpenAIJobAnalysisClient/OpenAIJobMatchClient. The actual
 * HTTP-transport mechanics live in the shared
 * App\Support\OpenAIResponsesApiClient — reused as-is, with zero
 * changes needed, validating that abstraction was drawn at the right
 * level during the JobMatch milestone. This class owns only what's
 * specific to Resume Selection: its own schema name/token budget, its
 * own domain exception, and its own *ProviderResponse DTO. See
 * docs/resume-variant-generation.md.
 */
final class OpenAIResumeSelectionClient implements GeneratesResumeSelection
{
    /**
     * The full eligible corpus can be substantially larger than a
     * per-finding-bounded payload, and the structured response covers
     * every role/bullet-group/target-term decision at once.
     */
    private const MAX_OUTPUT_TOKENS = 12000;

    public function __construct(
        private readonly string $apiKey,
        private readonly string $model,
        private readonly OpenAIResponsesApiClient $transport = new OpenAIResponsesApiClient,
    ) {}

    /**
     * @param  array<string, mixed>  $schema
     */
    public function generate(string $systemPrompt, string $userPrompt, array $schema): ResumeSelectionProviderResponse
    {
        try {
            $result = $this->transport->call(
                apiKey: $this->apiKey,
                model: $this->model,
                systemPrompt: $systemPrompt,
                userPrompt: $userPrompt,
                schema: $schema,
                schemaName: 'resume_selection',
                maxOutputTokens: self::MAX_OUTPUT_TOKENS,
                logPrefix: 'Resume Selection generation',
            );
        } catch (OpenAIResponsesApiException $e) {
            throw new ResumeGenerationProviderException($e->getMessage(), previous: $e);
        }

        return new ResumeSelectionProviderResponse(
            provider: 'openai',
            model: $result->model,
            structuredContent: $result->structuredContent,
        );
    }
}
