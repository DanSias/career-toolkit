<?php

namespace App\Support\ResumeVariant\Providers;

use App\Contracts\GeneratesResumeSelection;
use App\Exceptions\ResumeGenerationProviderException;
use App\Support\OllamaChatCompletionsClient;
use App\Support\OllamaChatCompletionsException;
use App\Support\ResumeVariant\ResumeSelectionProviderResponse;

/**
 * Calls a local/self-hosted Ollama server's OpenAI-compatible Chat
 * Completions endpoint for Resume Selection generation, using the same
 * `response_format: {type: "json_schema", json_schema: {...}}`
 * mechanism as OllamaJobMatchClient/OllamaJobAnalysisClient. The actual
 * HTTP-transport mechanics live in the shared, unmodified
 * App\Support\OllamaChatCompletionsClient — this class owns only what's
 * specific to Resume Selection: its own schema name/token budget and
 * its own *ProviderResponse DTO, exactly mirroring
 * OpenAIResumeSelectionClient's division of responsibility.
 *
 * This is the local-first default GeneratesResumeSelection
 * implementation — bound by
 * AppServiceProvider::resolveResumeSelectionProvider() whenever
 * AI_RESUME_SELECTION_PROVIDER is unset/blank or explicitly 'ollama'.
 * OpenAIResumeSelectionClient remains fully supported as an explicitly
 * selectable alternative (AI_RESUME_SELECTION_PROVIDER=openai), never
 * an automatic fallback target from here. See
 * docs/resume-variant-generation.md "Provider boundary".
 */
final class OllamaResumeSelectionClient implements GeneratesResumeSelection
{
    /**
     * The requested output-token budget sent as `max_tokens` — not a
     * provider-enforced ceiling (Ollama's OpenAI-compat endpoint treats
     * this as a soft budget, same caveat as OllamaJobMatchClient's own
     * budget; reported completion usage may exceed the requested value).
     *
     * A first live attempt at 6,000 (sized from the two real, already-
     * persisted Formic selections' compact-JSON output — 4,843/5,667
     * chars, ≈980-1,145 tokens at ~4.95 chars/token) returned
     * `finish_reason: length` at exactly 6,000 completion tokens against
     * a real, tokenizer-measured 35,030-token input — the response
     * shape's actual generation cost (reasoning plus a fully-populated
     * `experience`/`skills`/`selected_projects`/`target_term_usages`
     * tree) evidently exceeds what the historical *output-size* sample
     * alone predicted. Raised to 16,000 — the same number Job Match
     * uses, arrived at independently this time (context-budget-based:
     * the configured Ollama context is 65,536 tokens, and 16,000
     * leaves comfortable room beyond the measured ~35K-token input
     * under ordinary accounting), not copied from Job Match's own
     * reasoning.
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
    public function generate(string $systemPrompt, string $userPrompt, array $schema): ResumeSelectionProviderResponse
    {
        try {
            $result = $this->transport->call(
                baseUrl: $this->baseUrl,
                model: $this->model,
                systemPrompt: $systemPrompt,
                userPrompt: $userPrompt,
                schema: $schema,
                schemaName: 'resume_selection',
                maxOutputTokens: self::MAX_OUTPUT_TOKENS,
                timeoutSeconds: $this->timeoutSeconds,
                logPrefix: 'Resume Selection generation (Ollama)',
            );
        } catch (OllamaChatCompletionsException $e) {
            throw new ResumeGenerationProviderException($e->getMessage(), previous: $e);
        }

        return new ResumeSelectionProviderResponse(
            provider: 'ollama',
            model: $result->model,
            structuredContent: $result->structuredContent,
        );
    }
}
