<?php

use App\Support\ResumeDocument\GenerateResumePdf;
use Smalot\PdfParser\Document;
use Smalot\PdfParser\Parser as PdfParser;
use Tests\Support\PearlyResumeVariantFixture;

/**
 * A real headless-Chromium rendering regression against the Pearly
 * fixture — a complete, real, human-reviewed generated resume (12
 * bullets across 2 roles, 4 Skill groups, 2 Education entries), not
 * synthetic placeholder content. Inspects the actual PDF bytes
 * (structure, page geometry, page count) rather than asserting CSS
 * rule text exists — see resources/views/resume/print.blade.php's own
 * pagination-rule tests (ResumeVariantPreviewTest.php) for that
 * complementary, faster-running coverage.
 *
 * This is the rendering-layer safety primitive the local-first Wording
 * pipeline needs: given any generated ResumeVariant, we can now
 * deterministically ask "how many pages did this actually render to?"
 * This test fails the moment the Pearly fixture's own expected page
 * count changes — the first real tripwire against silent overflow as
 * generated content varies.
 */
function pearlyPdfDocument(): Document
{
    $variant = PearlyResumeVariantFixture::generate();
    $pdfBytes = app(GenerateResumePdf::class)->generate($variant);

    return (new PdfParser)->parseContent($pdfBytes);
}

it('produces a structurally valid PDF from the Pearly fixture', function () {
    $variant = PearlyResumeVariantFixture::generate();
    $pdfBytes = app(GenerateResumePdf::class)->generate($variant);

    expect($pdfBytes)->toStartWith('%PDF-')
        ->and(strlen($pdfBytes))->toBeGreaterThan(1000);
});

it('renders the Pearly fixture to exactly the expected page count — fails if this regresses', function () {
    $document = pearlyPdfDocument();

    // The real, already-verified page count for this exact fixture.
    // Change this number only when a deliberate content/layout change
    // to the Pearly fixture or the print template legitimately changes
    // its page count — never to silence an unexplained regression.
    expect(count($document->getPages()))->toBe(2);
});

it('renders every page at US Letter dimensions (612 x 792 points)', function () {
    $document = pearlyPdfDocument();

    foreach ($document->getPages() as $page) {
        $mediaBox = $page->get('MediaBox')->getContent();
        $widthPoints = (float) (string) $mediaBox[2]->getContent();
        $heightPoints = (float) (string) $mediaBox[3]->getContent();

        expect($widthPoints)->toBe(612.0)
            ->and($heightPoints)->toBe(792.0);
    }
});

it('does not leave an accidental, near-empty trailing page', function () {
    $document = pearlyPdfDocument();
    $pages = $document->getPages();
    $lastPage = $pages[count($pages) - 1];

    // A near-empty trailing page (the exact failure mode a prior manual
    // pagination pass fixed — see the "Improve resume print pagination"
    // commit) would extract to a very short, mostly-whitespace text
    // block. A real content page carries substantially more text than
    // that.
    expect(mb_strlen(trim($lastPage->getText())))->toBeGreaterThan(200);
});

it('renders the real Pearly content, not truncated or corrupted, in the final PDF text', function () {
    $document = pearlyPdfDocument();
    $text = collect($document->getPages())->map(fn ($page) => $page->getText())->implode(' ');

    expect($text)
        ->toContain('RocketGate')
        ->toContain('Pearson Online Learning Services')
        ->toContain('Developer Support Engineer');
});
