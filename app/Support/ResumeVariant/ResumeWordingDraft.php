<?php

namespace App\Support\ResumeVariant;

/**
 * The complete, trusted, deterministically-validated output of the
 * Wording stage — pure employer-facing prose, strictly bounded to the
 * structure and evidence Selection already approved.
 */
final readonly class ResumeWordingDraft
{
    /**
     * @param  array<int, RoleWordingDraft>  $experience
     * @param  array<int, ProjectWordingDraft>  $selectedProjects
     */
    public function __construct(
        public string $summary,
        public array $experience,
        public array $selectedProjects,
    ) {}
}
