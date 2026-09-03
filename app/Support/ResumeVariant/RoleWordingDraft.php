<?php

namespace App\Support\ResumeVariant;

/**
 * Stage 2's generated bullet text for one Role, keyed back to the
 * matching RoleSelectionDraft by roleId.
 */
final readonly class RoleWordingDraft
{
    /**
     * @param  array<int, BulletWordingDraft>  $bullets
     */
    public function __construct(
        public int $roleId,
        public array $bullets,
    ) {}
}
