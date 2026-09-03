<?php

namespace App\Support\ResumeVariant;

/**
 * Stage 2's generated text for exactly one Selection-approved bullet
 * group, addressed back by array position only — this DTO carries no
 * evidence/citation fields at all, so Wording has no path to alter
 * lineage even in principle. The orchestrator attaches
 * ResumeVariantBulletCitation rows from the matching BulletGroupDraft,
 * never from anything Wording returns.
 */
final readonly class BulletWordingDraft
{
    public function __construct(
        public int $bulletGroupIndex,
        public string $text,
    ) {}
}
