<?php

namespace Tests\Support;

use App\Contracts\GeneratesJobAnalysis;
use App\Exceptions\JobAnalysisProviderException;
use App\Support\JobAnalysis\JobAnalysisProviderResponse;
use LogicException;

/**
 * A test double for GeneratesJobAnalysis — lets the deterministic test
 * suite exercise the full generation pipeline (prompt -> provider ->
 * validate -> verify -> persist) with zero real network calls, and
 * records exactly what the orchestrator sent it so tests can assert the
 * request never carries anything beyond JobPosting information.
 */
final class FakeJobAnalysisProvider implements GeneratesJobAnalysis
{
    private ?JobAnalysisProviderResponse $response = null;

    private ?JobAnalysisProviderException $failure = null;

    public ?string $capturedSystemPrompt = null;

    public ?string $capturedUserPrompt = null;

    /** @var array<string, mixed>|null */
    public ?array $capturedSchema = null;

    public int $callCount = 0;

    public function willReturn(JobAnalysisProviderResponse $response): static
    {
        $this->response = $response;
        $this->failure = null;

        return $this;
    }

    public function willFail(JobAnalysisProviderException $failure): static
    {
        $this->failure = $failure;
        $this->response = null;

        return $this;
    }

    /**
     * @param  array<string, mixed>  $schema
     */
    public function generate(string $systemPrompt, string $userPrompt, array $schema): JobAnalysisProviderResponse
    {
        $this->callCount++;
        $this->capturedSystemPrompt = $systemPrompt;
        $this->capturedUserPrompt = $userPrompt;
        $this->capturedSchema = $schema;

        if ($this->failure !== null) {
            throw $this->failure;
        }

        return $this->response ?? throw new LogicException('FakeJobAnalysisProvider has no configured response.');
    }
}
