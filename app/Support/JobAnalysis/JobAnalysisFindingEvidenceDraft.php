<?php

namespace App\Support\JobAnalysis;

/**
 * One evidence entry — excerpt is always the exact, untouched text of
 * the source segment a validated evidence_refs id resolved to (see
 * GenerateJobAnalysis::toEvidenceDraft()), never model-generated text.
 * Not an Eloquent model; see GenerateJobAnalysis for where this is
 * finally persisted.
 */
final readonly class JobAnalysisFindingEvidenceDraft
{
    public function __construct(
        public string $excerpt,
        public ?string $sourceSection,
        public ?string $sourceLocator,
    ) {}
}
