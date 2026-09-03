<?php

namespace App\Contracts;

use App\Exceptions\ResumeGenerationProviderException;
use App\Support\ResumeVariant\ResumeWordingProviderResponse;

/**
 * The entire seam between Resume Wording generation and whichever
 * model provider answers it. See App\Contracts\GeneratesResumeSelection
 * for why this is a separate interface rather than a shared one.
 */
interface GeneratesResumeWording
{
    /**
     * @param  array<string, mixed>  $schema  JSON Schema the structured response must satisfy.
     *
     * @throws ResumeGenerationProviderException on any transport/provider failure.
     */
    public function generate(string $systemPrompt, string $userPrompt, array $schema): ResumeWordingProviderResponse;
}
