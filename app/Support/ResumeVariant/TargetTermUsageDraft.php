<?php

namespace App\Support\ResumeVariant;

use App\Enums\ResumeClaimPosture;
use App\Enums\ResumeQualifiedPhrase;
use App\Enums\ResumeTermUsageLocation;

/**
 * One deliberate decision about how a specific job-relevant target term
 * is positioned in this resume. `roleId`/`bulletGroupIndex` locate the
 * owning bullet when `location` is Bullet (both null when Summary) —
 * validated, after Selection responds, against the bullet groups that
 * same response actually declared for that role. `careerFactKeys` are
 * the facts this specific term claim is grounded in; for a `Qualified`
 * posture, the rendered clause's "actual technologies" are derived from
 * these facts' attached canonical Skills, never a separately declared
 * string.
 */
final readonly class TargetTermUsageDraft
{
    /**
     * @param  array<int, string>  $careerFactKeys
     */
    public function __construct(
        public string $term,
        public int $jobAnalysisFindingId,
        public ResumeClaimPosture $posture,
        public ResumeTermUsageLocation $location,
        public ?int $roleId,
        public ?int $bulletGroupIndex,
        public ResumeQualifiedPhrase $relationshipPhraseKey,
        public array $careerFactKeys,
    ) {}
}
