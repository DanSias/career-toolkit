<?php

namespace App\Contracts;

/**
 * Implemented by every model that CareerFact::attributable may point at
 * (CareerProfile, Employer, Role, Project). Answers "which CareerProfile
 * does this belong to?" Role still traverses `role.employer.career_profile_id`
 * — no denormalized column of its own. Project stores its own
 * `career_profile_id` directly (required for both professional and
 * independent projects; see docs/domain-model.md "Project ownership")
 * rather than traversing `role.employer`, since an independent Project
 * has no Role to traverse through at all.
 *
 * This is what CareerFact's attribution-integrity check compares against
 * its own `career_profile_id`. See docs/domain-model.md.
 */
interface HasCareerProfileOwnership
{
    public function ownerCareerProfileId(): ?int;
}
