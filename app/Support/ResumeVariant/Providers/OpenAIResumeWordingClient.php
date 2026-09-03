<?php

namespace App\Support\ResumeVariant\Providers;

use App\Contracts\GeneratesResumeWording;
use App\Exceptions\ResumeGenerationProviderException;
use App\Support\OpenAIResponsesApiClient;
use App\Support\OpenAIResponsesApiException;
use App\Support\ResumeVariant\ResumeWordingProviderResponse;

/**
 * Calls OpenAI's Responses API for Resume Wording generation. See
 * OpenAIResumeSelectionClient's docblock — same shared-transport
 * reasoning applies here.
 */
final class OpenAIResumeWordingClient implements GeneratesResumeWording
{
    /**
     * Smaller than Selection's budget — this stage only ever produces
     * prose for the bullets/summary Selection already approved, never
     * the full evidence graph.
     */
    private const MAX_OUTPUT_TOKENS = 6000;

    public function __construct(
        private readonly string $apiKey,
        private readonly string $model,
        private readonly OpenAIResponsesApiClient $transport = new OpenAIResponsesApiClient,
    ) {}

    /**
     * @param  array<string, mixed>  $schema
     */
    public function generate(string $systemPrompt, string $userPrompt, array $schema): ResumeWordingProviderResponse
    {
        try {
            $result = $this->transport->call(
                apiKey: $this->apiKey,
                model: $this->model,
                systemPrompt: $systemPrompt,
                userPrompt: $userPrompt,
                schema: $schema,
                schemaName: 'resume_wording',
                maxOutputTokens: self::MAX_OUTPUT_TOKENS,
                logPrefix: 'Resume Wording generation',
            );
        } catch (OpenAIResponsesApiException $e) {
            throw new ResumeGenerationProviderException($e->getMessage(), previous: $e);
        }

        return new ResumeWordingProviderResponse(
            provider: 'openai',
            model: $result->model,
            structuredContent: $result->structuredContent,
        );
    }
}
