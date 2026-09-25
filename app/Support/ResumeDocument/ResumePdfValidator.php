<?php

namespace App\Support\ResumeDocument;

use App\Exceptions\InvalidResumePdfException;
use App\Exceptions\ResumePdfPageBudgetExceededException;
use Smalot\PdfParser\Parser as PdfParser;
use Throwable;

/**
 * The final artifact-acceptance gate for an exported resume PDF —
 * deliberately separate from GenerateResumePdf (which only renders):
 * this class inspects an already-generated PDF's bytes and enforces
 * whether it is acceptable to hand to a candidate for submission.
 * Mirrors this codebase's established validator convention
 * (ResumeSelectionResponseValidator / ResumeWordingResponseValidator) —
 * a single-purpose, deterministic pass/fail check with a typed
 * exception on failure, never a silent transformation of its input.
 *
 * This is distinct in purpose from GenerateResumePdfTest.php's exact
 * Pearly page-count regression: that test is a renderer/layout
 * regression tripwire for one specific fixture (fails if the *known*
 * count for that fixture ever changes, in either direction). This
 * class is a universal runtime rule applied to any ResumeVariant's PDF
 * export (fails only when a real result exceeds the target,
 * regardless of what count is "expected" for that variant). Neither
 * replaces the other.
 *
 * Never used to enforce anything Selection is responsible for
 * upstream (e.g. Selected Projects cardinality — see
 * ResumeSelectionResponseValidator::MAX_SELECTED_PROJECTS) — this is
 * the last line of defense against the actual rendered artifact, not a
 * restatement of a content-selection rule.
 */
final class ResumePdfValidator
{
    private const MAX_PAGES = 2;

    /**
     * @throws InvalidResumePdfException if the bytes cannot be parsed as a PDF at all.
     * @throws ResumePdfPageBudgetExceededException if the PDF parses but exceeds MAX_PAGES.
     */
    public function validate(string $pdfBytes): void
    {
        try {
            $document = (new PdfParser)->parseContent($pdfBytes);
        } catch (Throwable $e) {
            throw new InvalidResumePdfException('Generated PDF could not be parsed: '.$e->getMessage(), $e);
        }

        $pageCount = count($document->getPages());

        if ($pageCount > self::MAX_PAGES) {
            throw new ResumePdfPageBudgetExceededException($pageCount, self::MAX_PAGES);
        }
    }
}
