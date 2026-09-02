<?php

namespace App\Support\JobAnalysis\Providers;

use App\Contracts\GeneratesJobAnalysis;
use App\Exceptions\JobAnalysisProviderException;
use App\Support\JobAnalysis\JobAnalysisProviderResponse;
use App\Support\OpenAIResponsesApiClient;
use App\Support\OpenAIResponsesApiException;

/**
 * Calls OpenAI's Responses API for JobAnalysis generation, using
 * OpenAI's native structured-output mechanism (`text.format` with
 * `type: "json_schema"`, `strict: true`) — not a JSON-mode or
 * forced-function-call workaround. No temperature/top_p is sent. The
 * actual HTTP-transport mechanics live in the shared
 * App\Support\OpenAIResponsesApiClient (used identically by
 * App\Support\JobMatch\Providers\OpenAIJobMatchClient) — this class
 * owns only what's specific to JobAnalysis: its own schema name/token
 * budget, its own domain exception, and its own *ProviderResponse DTO.
 * See docs/job-analysis-generation.md.
 *
 * This class knows nothing about JobPosting, Eloquent, or any
 * candidate-side model — it only ever sees the plain prompt strings and
 * schema array the orchestrator hands it. See
 * app/Contracts/GeneratesJobAnalysis.php.
 */
final class OpenAIJobAnalysisClient implements GeneratesJobAnalysis
{
    /**
     * Generous enough for a multi-finding analysis with several
     * evidence rows each; not user-configurable — this is an
     * implementation detail of the provider call, not a product knob.
     */
    private const MAX_OUTPUT_TOKENS = 8192;

    public function __construct(
        private readonly string $apiKey,
        private readonly string $model,
        private readonly OpenAIResponsesApiClient $transport = new OpenAIResponsesApiClient,
    ) {}

    /**
     * @param  array<string, mixed>  $schema
     */
    public function generate(string $systemPrompt, string $userPrompt, array $schema): JobAnalysisProviderResponse
    {
        try {
            $result = $this->transport->call(
                apiKey: $this->apiKey,
                model: $this->model,
                systemPrompt: $systemPrompt,
                userPrompt: $userPrompt,
                schema: $schema,
                schemaName: 'job_analysis',
                maxOutputTokens: self::MAX_OUTPUT_TOKENS,
                logPrefix: 'JobAnalysis generation',
            );
        } catch (OpenAIResponsesApiException $e) {
            throw new JobAnalysisProviderException($e->getMessage(), previous: $e);
        }

        return new JobAnalysisProviderResponse(
            provider: 'openai',
            model: $result->model,
            structuredContent: $result->structuredContent,
        );
    }
}
