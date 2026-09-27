<?php

namespace App\Support\JobMatch\Providers;

use App\Contracts\GeneratesJobMatch;
use App\Exceptions\JobMatchProviderException;
use App\Support\JobMatch\JobMatchProviderResponse;
use App\Support\OllamaChatCompletionsClient;
use App\Support\OllamaChatCompletionsException;
use App\Support\ProviderDiagnostics;

/**
 * Calls a local/self-hosted Ollama server's OpenAI-compatible Chat
 * Completions endpoint for JobMatch generation, using the same
 * `response_format: {type: "json_schema", json_schema: {...}}`
 * mechanism as OllamaJobAnalysisClient. The actual HTTP-transport
 * mechanics live in the shared App\Support\OllamaChatCompletionsClient,
 * reused unchanged — this class owns only what's specific to JobMatch:
 * its own schema name/token budget and its own *ProviderResponse DTO,
 * exactly mirroring OpenAIJobMatchClient's division of responsibility
 * (and OllamaJobAnalysisClient's own division of responsibility versus
 * OpenAIJobAnalysisClient).
 *
 * Implements the EXISTING GeneratesJobMatch contract unchanged —
 * whichever JobMatchPrompt version GenerateJobMatch is currently wired
 * to (its system/user prompts and JSON schema) and
 * JobMatchResponseValidator are all reused as-is by whatever
 * orchestrator constructs this in place of OpenAIJobMatchClient. This
 * class knows nothing about CareerProfile, JobAnalysis, or Eloquent,
 * same as its OpenAI counterpart. See app/Contracts/GeneratesJobMatch.php.
 *
 * This is the local-first default GeneratesJobMatch implementation —
 * bound by AppServiceProvider::resolveJobMatchProvider() whenever
 * AI_JOB_MATCH_PROVIDER is unset/blank or explicitly 'ollama'.
 * OpenAIJobMatchClient remains fully supported as an explicitly
 * selectable alternative (AI_JOB_MATCH_PROVIDER=openai), never an
 * automatic fallback target from here. See
 * docs/job-match-generation.md "Provider boundary".
 */
final class OllamaJobMatchClient implements GeneratesJobMatch
{
    /**
     * The requested output-token budget sent as `max_tokens` — not a
     * provider-enforced ceiling. A live qwen3.8:27b/job-match-v3
     * evaluation at this exact budget returned `finish_reason: stop`
     * with 18,115 completion tokens actually used, comfortably above
     * this "requested" number; Ollama's OpenAI-compat endpoint treats
     * `max_tokens` as a soft budget, not a hard cutoff, so this value
     * documents the validated request, not a guarantee. Larger than
     * JobAnalysis's 8,192 for the same reason OpenAIJobMatchClient's
     * is: a JobMatch response can contain up to one
     * matches/education_matches entry per (finding × candidate
     * fact/education row) combination the model judges relevant,
     * scaling with candidate dataset size in a way JobAnalysis's
     * response never does.
     */
    private const MAX_OUTPUT_TOKENS = 16000;

    public function __construct(
        private readonly string $baseUrl,
        private readonly string $model,
        private readonly int $timeoutSeconds,
        private readonly OllamaChatCompletionsClient $transport = new OllamaChatCompletionsClient,
    ) {}

    /**
     * @param  array<string, mixed>  $schema
     */
    public function generate(string $systemPrompt, string $userPrompt, array $schema): JobMatchProviderResponse
    {
        try {
            $result = $this->transport->call(
                baseUrl: $this->baseUrl,
                model: $this->model,
                systemPrompt: $systemPrompt,
                userPrompt: $userPrompt,
                schema: $schema,
                schemaName: 'job_match',
                maxOutputTokens: self::MAX_OUTPUT_TOKENS,
                timeoutSeconds: $this->timeoutSeconds,
                logPrefix: 'JobMatch generation (Ollama)',
            );
        } catch (OllamaChatCompletionsException $e) {
            throw new JobMatchProviderException($e->getMessage(), diagnostics: new ProviderDiagnostics(
                model: $e->model,
                finishReason: $e->finishReason,
                usage: $e->usage,
                maxOutputTokens: self::MAX_OUTPUT_TOKENS,
                timeoutSeconds: $this->timeoutSeconds,
            ), previous: $e);
        }

        return new JobMatchProviderResponse(
            provider: 'ollama',
            model: $result->model,
            structuredContent: $result->structuredContent,
        );
    }
}
