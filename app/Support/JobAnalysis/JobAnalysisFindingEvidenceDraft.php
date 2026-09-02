<?php

namespace App\Support\JobAnalysis;

/**
 * One evidence entry from an already-validated, already
 * evidence-verified provider response — trusted to be a verbatim
 * excerpt of the source JobPosting's description by the time this
 * object exists. Not an Eloquent model; see GenerateJobAnalysis for
 * where this is finally persisted.
 */
final readonly class JobAnalysisFindingEvidenceDraft
{
    public function __construct(
        public string $excerpt,
        public ?string $sourceSection,
        public ?string $sourceLocator,
    ) {}
}
