<?php

namespace Tests\Support;

use App\Contracts\GeneratesJobMatch;
use App\Exceptions\JobMatchProviderException;
use App\Support\JobMatch\JobMatchProviderResponse;
use LogicException;

/**
 * A test double for GeneratesJobMatch — lets the deterministic test
 * suite exercise the full matching pipeline (payload build -> provider
 * -> validate -> persist) with zero real network calls, and records
 * exactly what the orchestrator sent it. Mirrors
 * Tests\Support\FakeJobAnalysisProvider.
 */
final class FakeJobMatchProvider implements GeneratesJobMatch
{
    private ?JobMatchProviderResponse $response = null;

    private ?JobMatchProviderException $failure = null;

    public ?string $capturedSystemPrompt = null;

    public ?string $capturedUserPrompt = null;

    /** @var array<string, mixed>|null */
    public ?array $capturedSchema = null;

    public int $callCount = 0;

    public function willReturn(JobMatchProviderResponse $response): static
    {
        $this->response = $response;
        $this->failure = null;

        return $this;
    }

    public function willFail(JobMatchProviderException $failure): static
    {
        $this->failure = $failure;
        $this->response = null;

        return $this;
    }

    /**
     * @param  array<string, mixed>  $schema
     */
    public function generate(string $systemPrompt, string $userPrompt, array $schema): JobMatchProviderResponse
    {
        $this->callCount++;
        $this->capturedSystemPrompt = $systemPrompt;
        $this->capturedUserPrompt = $userPrompt;
        $this->capturedSchema = $schema;

        if ($this->failure !== null) {
            throw $this->failure;
        }

        return $this->response ?? throw new LogicException('FakeJobMatchProvider has no configured response.');
    }
}
