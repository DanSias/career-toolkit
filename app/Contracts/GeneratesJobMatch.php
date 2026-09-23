<?php

namespace App\Contracts;

use App\Exceptions\JobMatchProviderException;
use App\Support\JobMatch\JobMatchProviderResponse;

/**
 * The entire seam between JobMatch generation and whichever model
 * provider answers it. Deliberately takes plain strings/arrays, never a
 * CareerProfile, JobAnalysis, or any Eloquent model — a provider
 * implementation has no way to reach anything beyond what was already
 * normalized into the prompt strings/schema it's handed. Mirrors
 * App\Contracts\GeneratesJobAnalysis exactly — a deliberately separate
 * interface, not a reuse of it, since the two calls have genuinely
 * different input/output shapes. See docs/job-match-generation.md.
 *
 * Two providers implement this:
 * App\Support\JobMatch\Providers\OllamaJobMatchClient (local-first
 * default) and App\Support\JobMatch\Providers\OpenAIJobMatchClient
 * (explicitly selectable). This interface exists so a provider — or a
 * fake for tests — is a new class, not a change to the orchestrator or
 * the domain layer. See App\Providers\AppServiceProvider::resolveJobMatchProvider().
 */
interface GeneratesJobMatch
{
    /**
     * @param  array<string, mixed>  $schema  JSON Schema the structured response must satisfy.
     *
     * @throws JobMatchProviderException on any transport/provider failure —
     *                                   a non-2xx response, an exhausted retry budget, or a response
     *                                   whose content cannot be decoded as JSON at all.
     */
    public function generate(string $systemPrompt, string $userPrompt, array $schema): JobMatchProviderResponse;
}
