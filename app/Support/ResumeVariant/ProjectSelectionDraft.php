<?php

namespace App\Support\ResumeVariant;

/**
 * One Selection-approved independent Project, and the flat set of
 * CareerFacts it's grounded in — deliberately not sub-grouped into
 * bullet groups the way Experience roles are: v1 renders exactly one
 * bullet per selected Project, so one evidence set is sufficient. See
 * docs/domain-model.md "ResumeVariant" -> "Selected Projects".
 */
final readonly class ProjectSelectionDraft
{
    /**
     * @param  array<int, string>  $careerFactKeys
     */
    public function __construct(
        public int $projectId,
        public int $order,
        public array $careerFactKeys,
    ) {}
}
