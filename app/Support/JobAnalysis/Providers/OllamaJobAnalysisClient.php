<?php

namespace App\Support\JobAnalysis\Providers;

use App\Contracts\GeneratesJobAnalysis;
use App\Exceptions\JobAnalysisProviderException;
use App\Support\JobAnalysis\JobAnalysisProviderResponse;
use App\Support\OllamaChatCompletionsClient;
use App\Support\OllamaChatCompletionsException;

/**
 * Calls a local/self-hosted Ollama server's OpenAI-compatible Chat
 * Completions endpoint for JobAnalysis generation, using
 * `response_format: {type: "json_schema", json_schema: {...}}` —
 * Ollama's documented OpenAI-compatible structured-output mechanism.
 * The actual HTTP-transport mechanics live in the shared
 * App\Support\OllamaChatCompletionsClient — this class owns only
 * what's specific to JobAnalysis: its own schema name/token budget and
 * its own *ProviderResponse DTO, exactly mirroring
 * OpenAIJobAnalysisClient's division of responsibility.
 *
 * Implements the EXISTING GeneratesJobAnalysis contract unchanged —
 * JobAnalysisPromptV2's system/user prompts and JSON schema,
 * JobAnalysisResponseValidator, and EvidenceExcerptVerifier are all
 * reused as-is by whatever orchestrator constructs this in place of
 * OpenAIJobAnalysisClient. This class knows nothing about JobPosting,
 * Eloquent, or any candidate-side model, same as its OpenAI
 * counterpart. See app/Contracts/GeneratesJobAnalysis.php.
 *
 * Not wired into AppServiceProvider's default binding — this is a
 * deliberately unplugged, second implementation of the same contract,
 * constructed explicitly wherever it's being evaluated (tests, the
 * opt-in live harness in tests/Llm). OpenAIJobAnalysisClient remains
 * the only default.
 */
final class OllamaJobAnalysisClient implements GeneratesJobAnalysis
{
    /**
     * Same budget as OpenAIJobAnalysisClient — no protocol reason for
     * JobAnalysis's own output shape to need a different one here;
     * `max_tokens` on Chat Completions is an output-only budget, same
     * as `max_output_tokens` on the Responses API.
     */
    private const MAX_OUTPUT_TOKENS = 8192;

    public function __construct(
        private readonly string $baseUrl,
        private readonly string $model,
        private readonly int $timeoutSeconds,
        private readonly OllamaChatCompletionsClient $transport = new OllamaChatCompletionsClient,
    ) {}

    /**
     * @param  array<string, mixed>  $schema
     */
    public function generate(string $systemPrompt, string $userPrompt, array $schema): JobAnalysisProviderResponse
    {
        try {
            $result = $this->transport->call(
                baseUrl: $this->baseUrl,
                model: $this->model,
                systemPrompt: $systemPrompt,
                userPrompt: $userPrompt,
                schema: $schema,
                schemaName: 'job_analysis',
                maxOutputTokens: self::MAX_OUTPUT_TOKENS,
                timeoutSeconds: $this->timeoutSeconds,
                logPrefix: 'JobAnalysis generation (Ollama)',
            );
        } catch (OllamaChatCompletionsException $e) {
            throw new JobAnalysisProviderException($e->getMessage(), previous: $e);
        }

        return new JobAnalysisProviderResponse(
            provider: 'ollama',
            model: $result->model,
            structuredContent: $result->structuredContent,
        );
    }
}
