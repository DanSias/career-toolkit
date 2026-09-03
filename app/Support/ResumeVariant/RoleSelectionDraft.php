<?php

namespace App\Support\ResumeVariant;

/**
 * One canonical Role's selected Experience-section content.
 * `displayTitle` is validated to be either the Role's full canonical
 * title verbatim or one of its "/"-delimited segments verbatim —
 * enum-constrained dynamically in the schema and re-checked
 * independently in ResumeSelectionResponseValidator; never a freely
 * rewritten string. Role ordering itself is never part of this draft —
 * it's computed deterministically from Role dates at persistence time,
 * not a Selection decision.
 */
final readonly class RoleSelectionDraft
{
    /**
     * @param  array<int, BulletGroupDraft>  $bulletGroups
     */
    public function __construct(
        public int $roleId,
        public string $displayTitle,
        public array $bulletGroups,
    ) {}
}
