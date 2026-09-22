<?php

namespace App\Support;

/**
 * The successful, decoded result of one Ollama Chat Completions
 * structured-output call — mirrors OpenAIResponsesApiResult's shape,
 * but is a distinct type since it comes from a distinct transport
 * (OllamaChatCompletionsClient) speaking a distinct protocol (Chat
 * Completions, not the Responses API). Not merged with
 * OpenAIResponsesApiResult on purpose: see
 * OllamaChatCompletionsClient's docblock for why this stays separate
 * for now.
 *
 * Carries $finishReason and $usage in addition to OpenAIResponsesApiResult's
 * shape — both already present on the raw Chat Completions response
 * body OllamaChatCompletionsClient decodes, previously read internally
 * (for the truncation check and usage logging) but discarded rather
 * than returned. Added because a live-evaluation caller
 * (tests/Llm/OllamaJobAnalysisLiveTest.php) needs them to report on
 * model quality, not because every caller needs them — a concrete
 * *ProviderResponse (e.g. JobAnalysisProviderResponse) is still free to
 * ignore both, exactly as OllamaJobAnalysisClient does today.
 */
final readonly class OllamaChatCompletionsResult
{
    /**
     * @param  array<string, mixed>  $structuredContent
     * @param  array<string, mixed>|null  $usage
     */
    public function __construct(
        public string $model,
        public array $structuredContent,
        public ?string $finishReason = null,
        public ?array $usage = null,
    ) {}
}
