<?php

namespace App\Contracts;

use App\Exceptions\ResumeGenerationProviderException;
use App\Support\ResumeVariant\ResumeSelectionProviderResponse;

/**
 * The entire seam between Resume Selection generation and whichever
 * model provider answers it. Deliberately takes plain strings/arrays,
 * never a CareerProfile, JobMatch, or any Eloquent model. Mirrors
 * App\Contracts\GeneratesJobMatch exactly — a deliberately separate
 * interface from GeneratesResumeWording, since the two calls have
 * genuinely different input/output shapes, even though both happen to
 * share the same underlying HTTP transport. Two providers implement
 * this: App\Support\ResumeVariant\Providers\OllamaResumeSelectionClient
 * (local-first default) and OpenAIResumeSelectionClient (explicitly
 * selectable). See docs/resume-variant-generation.md.
 */
interface GeneratesResumeSelection
{
    /**
     * @param  array<string, mixed>  $schema  JSON Schema the structured response must satisfy.
     *
     * @throws ResumeGenerationProviderException on any transport/provider failure.
     */
    public function generate(string $systemPrompt, string $userPrompt, array $schema): ResumeSelectionProviderResponse;
}
