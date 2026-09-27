<?php

namespace App\Support;

/**
 * Non-content, safe-to-log metadata about a failed provider call —
 * which model actually answered, why generation stopped
 * (`finish_reason`), how many tokens were used, and what this call was
 * actually configured to allow (the requested output-token budget and
 * the provider timeout). Deliberately never carries prompt content,
 * response content, or any candidate/job/resume text — only the kind
 * of metadata already logged on a *successful* call (see
 * OllamaChatCompletionsClient's own "Ollama usage" info log) plus the
 * two configured values needed to judge a truncation failure without
 * reproducing it (e.g. completion_tokens landing exactly at
 * maxOutputTokens is the signature of a token-budget shortfall, not a
 * model/prompt defect).
 *
 * One shared, reusable shape rather than each domain provider
 * exception (JobAnalysisProviderException, JobMatchProviderException,
 * ResumeGenerationProviderException) duplicating the same four/five
 * fields independently. Optional everywhere: a provider client that
 * cannot supply this (e.g. OpenAIResponsesApiException currently
 * carries no equivalent fields at all) simply omits it — no exception
 * class requires it, and no existing call site's behavior changes by
 * not passing one.
 */
final readonly class ProviderDiagnostics
{
    /**
     * @param  array<string, mixed>|null  $usage
     */
    public function __construct(
        public ?string $model = null,
        public ?string $finishReason = null,
        public ?array $usage = null,
        public ?int $maxOutputTokens = null,
        public ?int $timeoutSeconds = null,
    ) {}

    /**
     * A flat, log-ready context array — every key present even when
     * its value is null, so a log line always has a predictable shape
     * and a null reads as "checked, unavailable" rather than silently
     * omitted.
     *
     * @return array<string, mixed>
     */
    public function toLogContext(): array
    {
        return [
            'provider_model' => $this->model,
            'finish_reason' => $this->finishReason,
            'prompt_tokens' => $this->usage['prompt_tokens'] ?? null,
            'completion_tokens' => $this->usage['completion_tokens'] ?? null,
            'total_tokens' => $this->usage['total_tokens'] ?? null,
            'max_output_tokens_configured' => $this->maxOutputTokens,
            'provider_timeout_seconds' => $this->timeoutSeconds,
        ];
    }
}
