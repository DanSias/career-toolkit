<?php

namespace Tests\Support;

use App\Contracts\GeneratesResumeWording;
use App\Exceptions\ResumeGenerationProviderException;
use App\Support\ResumeVariant\ResumeWordingProviderResponse;
use LogicException;

/**
 * A test double for GeneratesResumeWording. Mirrors
 * Tests\Support\FakeResumeSelectionProvider.
 */
final class FakeResumeWordingProvider implements GeneratesResumeWording
{
    private ?ResumeWordingProviderResponse $response = null;

    private ?ResumeGenerationProviderException $failure = null;

    public ?string $capturedSystemPrompt = null;

    public ?string $capturedUserPrompt = null;

    /** @var array<string, mixed>|null */
    public ?array $capturedSchema = null;

    public int $callCount = 0;

    public function willReturn(ResumeWordingProviderResponse $response): static
    {
        $this->response = $response;
        $this->failure = null;

        return $this;
    }

    public function willFail(ResumeGenerationProviderException $failure): static
    {
        $this->failure = $failure;
        $this->response = null;

        return $this;
    }

    /**
     * @param  array<string, mixed>  $schema
     */
    public function generate(string $systemPrompt, string $userPrompt, array $schema): ResumeWordingProviderResponse
    {
        $this->callCount++;
        $this->capturedSystemPrompt = $systemPrompt;
        $this->capturedUserPrompt = $userPrompt;
        $this->capturedSchema = $schema;

        if ($this->failure !== null) {
            throw $this->failure;
        }

        return $this->response ?? throw new LogicException('FakeResumeWordingProvider has no configured response.');
    }
}
