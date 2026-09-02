<?php

namespace App\Contracts;

use App\Exceptions\JobAnalysisProviderException;
use App\Support\JobAnalysis\JobAnalysisProviderResponse;

/**
 * The entire seam between JobAnalysis generation and whichever model
 * provider answers it. Deliberately takes plain strings/arrays, never a
 * JobPosting or any Eloquent model — a provider implementation has no
 * way to reach candidate data even by accident, because nothing here
 * gives it a path to any model at all. See docs/job-analysis-generation.md.
 *
 * A single provider (OpenAI) implements this for v1
 * (App\Support\JobAnalysis\Providers\OpenAIJobAnalysisClient). This
 * interface exists so a second provider — or a fake for tests — is a
 * new class, not a change to the orchestrator or the domain layer.
 */
interface GeneratesJobAnalysis
{
    /**
     * @param  array<string, mixed>  $schema  JSON Schema the structured response must satisfy.
     *
     * @throws JobAnalysisProviderException on any transport/provider failure —
     *                                      a non-2xx response, an exhausted retry budget, or a response
     *                                      whose content cannot be decoded as JSON at all.
     */
    public function generate(string $systemPrompt, string $userPrompt, array $schema): JobAnalysisProviderResponse;
}
