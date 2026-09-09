<?php

namespace App\Support\ResumeDocument;

/**
 * One Experience-section role block: employer name, resolved display
 * title, formatted date range, and its ordered bullet text — every
 * field read directly from a frozen ResumeVariantExperienceRole
 * snapshot (see App\Models\ResumeVariantExperienceRole), never
 * re-resolved from the live Employer/Role. Bullet text is plain
 * strings, not a richer per-bullet object — v1 deliberately does not
 * render a project label inline (see docs/domain-model.md
 * "ResumeVariant"), so there is no other per-bullet field to carry.
 */
final readonly class ResumeExperienceRole
{
    /**
     * @param  string[]  $bullets
     */
    public function __construct(
        public string $employerName,
        public string $displayTitle,
        public string $dateRangeLabel,
        public bool $isCurrent,
        public array $bullets,
    ) {}
}
