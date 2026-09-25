<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown by App\Support\ResumeDocument\ResumePdfValidator when a
 * successfully-parsed, structurally valid PDF exceeds the two-page
 * resume export target. Carries the actual measured page count so a
 * caller (ResumeVariantPdfController) can report it precisely rather
 * than a generic failure message. This is an artifact-acceptance
 * failure, not a rendering failure — the PDF itself was generated
 * successfully; it is simply too long to accept for export.
 */
final class ResumePdfPageBudgetExceededException extends RuntimeException
{
    public function __construct(public readonly int $pageCount, public readonly int $maxPages)
    {
        parent::__construct("Generated PDF has {$pageCount} pages, exceeding the {$maxPages}-page export limit.");
    }
}
