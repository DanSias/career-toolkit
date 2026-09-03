<?php

namespace App\Support\ResumeVariant;

/**
 * One Education record selected for the Education section, and its
 * display order. No generated wording — rendered directly from
 * canonical institution/degree/field/date fields.
 */
final readonly class EducationSelectionDraft
{
    public function __construct(
        public int $educationId,
        public int $order,
    ) {}
}
