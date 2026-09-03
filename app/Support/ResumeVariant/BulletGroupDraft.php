<?php

namespace App\Support\ResumeVariant;

/**
 * One Selection-approved unit of resume evidence destined to become
 * exactly one generated bullet — a set of CareerFacts grouped together
 * because Selection judged they combine into a single strong claim.
 * Stage 2 receives this group's evidence and produces text for it,
 * addressed back by array position (bulletGroupIndex within its
 * parent RoleSelectionDraft), never by re-declaring the evidence.
 */
final readonly class BulletGroupDraft
{
    /**
     * @param  array<int, string>  $careerFactKeys
     * @param  array<int, int>  $jobAnalysisFindingIds  Informational only — which requirements this bullet happens to speak to, never a completeness obligation.
     */
    public function __construct(
        public ?int $projectId,
        public int $order,
        public array $careerFactKeys,
        public array $jobAnalysisFindingIds,
    ) {}
}
