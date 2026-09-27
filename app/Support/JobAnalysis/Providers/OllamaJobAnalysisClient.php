<?php

namespace App\Support\JobAnalysis\Providers;

use App\Contracts\GeneratesJobAnalysis;
use App\Exceptions\JobAnalysisProviderException;
use App\Support\JobAnalysis\JobAnalysisProviderResponse;
use App\Support\OllamaChatCompletionsClient;
use App\Support\OllamaChatCompletionsException;
use App\Support\ProviderDiagnostics;

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
 * JobAnalysisPromptV4's system/user prompts and JSON schema, and
 * JobAnalysisResponseValidator, are reused as-is by whatever
 * orchestrator constructs this in place of OpenAIJobAnalysisClient.
 * This class knows nothing about JobPosting, Eloquent, or any
 * candidate-side model, same as its OpenAI counterpart. See
 * app/Contracts/GeneratesJobAnalysis.php.
 *
 * Wired into AppServiceProvider::resolveJobAnalysisProvider() as the
 * default when AI_JOB_ANALYSIS_PROVIDER is 'ollama' or unset (the
 * local-first default) — OpenAIJobAnalysisClient remains fully
 * supported as the explicitly-selectable 'openai' alternative, never
 * an automatic fallback. Also constructed directly wherever it's
 * evaluated in isolation (tests, the opt-in live harness in tests/Llm).
 */
final class OllamaJobAnalysisClient implements GeneratesJobAnalysis
{
    /**
     * Raised from 8192 (still OpenAIJobAnalysisClient's budget — no
     * evidence yet that path needs to change) after two controlled real
     * TRM Labs generations against qwen3.8:27b both hit
     * finish_reason:length before completing, at 11972 and then 12639
     * completion tokens against the 8192 budget — the second run after
     * JobAnalysisPromptV3's evidence-discipline tightening, which did
     * not resolve it (completion tokens went up, not down). 16000
     * matches the budget already used by OllamaJobMatchClient,
     * OllamaResumeSelectionClient, and OllamaResumeWordingClient. Not
     * yet proven sufficient for this posting — the next controlled TRM
     * retry is the test of that. See docs/job-analysis-generation.md.
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
            throw new JobAnalysisProviderException($e->getMessage(), diagnostics: new ProviderDiagnostics(
                model: $e->model,
                finishReason: $e->finishReason,
                usage: $e->usage,
                maxOutputTokens: self::MAX_OUTPUT_TOKENS,
                timeoutSeconds: $this->timeoutSeconds,
            ), previous: $e);
        }

        return new JobAnalysisProviderResponse(
            provider: 'ollama',
            model: $result->model,
            structuredContent: $result->structuredContent,
        );
    }
}
