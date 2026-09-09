<?php

namespace App\Support\ResumeVariant;

/**
 * Stage 2's generated text for one Selection-approved Selected-Projects
 * entry, keyed back to the matching ProjectSelectionDraft by
 * projectId. v1 always carries exactly one bullet — see
 * docs/domain-model.md "ResumeVariant" -> "Selected Projects" — but
 * this DTO deliberately carries a list, not a single string, so
 * raising the per-project bullet cap later is a schema/validator
 * change only, never a redesign of this shape.
 */
final readonly class ProjectWordingDraft
{
    /**
     * @param  array<int, string>  $bullets
     */
    public function __construct(
        public int $projectId,
        public array $bullets,
    ) {}
}
