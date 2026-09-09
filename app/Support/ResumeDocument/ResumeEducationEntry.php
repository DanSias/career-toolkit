<?php

namespace App\Support\ResumeDocument;

/**
 * One Education-section entry, read directly from a frozen
 * ResumeVariantEducationSelection snapshot row — never re-resolved
 * from the live Education record.
 */
final readonly class ResumeEducationEntry
{
    public function __construct(
        public string $degree,
        public ?string $fieldOfStudy,
        public string $institution,
        public string $dateRangeLabel,
    ) {}
}
