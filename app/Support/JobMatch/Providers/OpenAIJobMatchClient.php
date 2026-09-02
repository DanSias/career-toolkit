<?php

namespace App\Support\JobMatch\Providers;

use App\Contracts\GeneratesJobMatch;
use App\Exceptions\JobMatchProviderException;
use App\Support\JobMatch\JobMatchProviderResponse;
use App\Support\OpenAIResponsesApiClient;
use App\Support\OpenAIResponsesApiException;

/**
 * Calls OpenAI's Responses API for JobMatch generation, using the same
 * native structured-output mechanism as
 * App\Support\JobAnalysis\Providers\OpenAIJobAnalysisClient. The actual
 * HTTP-transport mechanics live in the shared
 * App\Support\OpenAIResponsesApiClient — this class owns only what's
 * specific to JobMatch: its own schema name/token budget, its own
 * domain exception, and its own *ProviderResponse DTO. See
 * docs/job-match-generation.md.
 *
 * This class knows nothing about CareerProfile, JobAnalysis, or
 * Eloquent — it only ever sees the plain prompt strings and schema
 * array the orchestrator hands it. See
 * app/Contracts/GeneratesJobMatch.php.
 */
final class OpenAIJobMatchClient implements GeneratesJobMatch
{
    /**
     * Larger than JobAnalysis's budget — a JobMatch response covers
     * every finding with potentially several support references and a
     * rationale each, a bigger structured object than a job posting's
     * own finding list.
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
    public function generate(string $systemPrompt, string $userPrompt, array $schema): JobMatchProviderResponse
    {
        try {
            $result = $this->transport->call(
                apiKey: $this->apiKey,
                model: $this->model,
                systemPrompt: $systemPrompt,
                userPrompt: $userPrompt,
                schema: $schema,
                schemaName: 'job_match',
                maxOutputTokens: self::MAX_OUTPUT_TOKENS,
                logPrefix: 'JobMatch generation',
            );
        } catch (OpenAIResponsesApiException $e) {
            throw new JobMatchProviderException($e->getMessage(), previous: $e);
        }

        return new JobMatchProviderResponse(
            provider: 'openai',
            model: $result->model,
            structuredContent: $result->structuredContent,
        );
    }
}
