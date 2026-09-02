<?php

namespace App\Support\JobMatch;

/**
 * A model provider's raw answer to one JobMatch generation request: the
 * decoded structured content (untrusted — not yet validated) plus
 * enough provider/model identity to populate JobMatch.generated_by.
 * Mirrors App\Support\JobAnalysis\JobAnalysisProviderResponse exactly.
 */
final readonly class JobMatchProviderResponse
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
     * The value persisted verbatim into JobMatch.generated_by — the
     * same "<provider>:<model>" convention JobAnalysis uses.
     */
    public function generatedBy(): string
    {
        return "{$this->provider}:{$this->model}";
    }
}
