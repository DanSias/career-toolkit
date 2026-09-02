<?php

namespace App\Support\JobAnalysis;

/**
 * A model provider's raw answer to one JobAnalysis generation request:
 * the decoded structured content (untrusted — not yet validated) plus
 * enough provider/model identity to populate JobAnalysis.generated_by.
 * Deliberately carries nothing else from the provider's HTTP response
 * (no headers, no token usage, no request id) — see
 * docs/job-analysis-generation.md for what generated_by/raw_response are
 * meant to capture.
 */
final readonly class JobAnalysisProviderResponse
{
    /**
     * @param  array<string, mixed>  $structuredContent
     */
    public function __construct(
        public string $provider,
        public string $model,
        public array $structuredContent,
    ) {}

    /**
     * The value persisted verbatim into JobAnalysis.generated_by —
     * one documented "<provider>:<model>" convention.
     */
    public function generatedBy(): string
    {
        return "{$this->provider}:{$this->model}";
    }
}
