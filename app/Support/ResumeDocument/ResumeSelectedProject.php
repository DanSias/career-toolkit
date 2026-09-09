<?php

namespace App\Support\ResumeDocument;

/**
 * One rendered Selected-Projects entry — an independent/personal
 * Project Selection chose to feature, entirely from
 * ResumeVariantProject's frozen snapshot data. v1 carries exactly one
 * bullet (see docs/domain-model.md "ResumeVariant" -> "Selected
 * Projects"); `bullet` is a single string here to match, even though
 * the underlying persistence keeps a real child bullet table so a
 * later cap increase is a DTO change only, never a persistence
 * redesign.
 */
final readonly class ResumeSelectedProject
{
    /**
     * @param  string[]  $technologies
     */
    public function __construct(
        public string $name,
        public array $technologies,
        public string $bullet,
        public ?ResumeLink $liveDemo,
        public ?ResumeLink $repository,
    ) {}
}
