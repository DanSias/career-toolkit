<?php

namespace App\Contracts;

/**
 * Implemented by every model that CareerFact::attributable may point at
 * (CareerProfile, Employer, Role, Project). Answers "which CareerProfile
 * does this belong to?" by traversing existing relationships — never by
 * storing a denormalized `career_profile_id` on Role/Project.
 *
 * This is what CareerFact's attribution-integrity check compares against
 * its own `career_profile_id`. See docs/domain-model.md.
 */
interface HasCareerProfileOwnership
{
    public function ownerCareerProfileId(): ?int;
}
