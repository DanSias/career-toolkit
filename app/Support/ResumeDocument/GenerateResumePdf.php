<?php

namespace App\Support\ResumeDocument;

use App\Models\ResumeVariant;
use HeadlessChromium\BrowserFactory;

/**
 * Renders one ResumeVariant to PDF bytes via headless Chromium
 * (chrome-php/chrome) — the exact same GenerateResumeDocument +
 * resources/views/resume/print.blade.php path the browser preview
 * uses, never a second PDF-specific template. No provider/inference
 * call, no live Employer/Role/Skill/Education re-resolution beyond
 * what GenerateResumeDocument itself already does, no network
 * dependency: the rendered HTML is handed to Chromium in-process via
 * Page::setHtml(), never an HTTP round trip to this app's own preview
 * route.
 *
 * Page geometry (US Letter, zero page margin) is set to match
 * print.blade.php's own `@page` rule exactly — see that file's
 * docblock for why this is the one physical-geometry source of truth
 * and why the margins here are 0, not a second, independent margin.
 * `displayHeaderFooter` is explicitly false so Chromium never injects
 * its own default title/URL/page-number header-footer.
 *
 * A fresh headless Chromium process is launched per call and closed
 * before returning — appropriate for a local, single-operator tool;
 * no persistent browser pool. `chrome-php/chrome`'s own AutoDiscover
 * finds the installed Chrome/Chromium binary (honoring a `CHROME_PATH`
 * environment variable override, e.g. for a Linux/CI environment
 * without the macOS default path) — no Career Toolkit-specific
 * configuration was added for this, since the library already covers
 * it. See docs/resume-variant-generation.md "PDF export".
 */
final class GenerateResumePdf
{
    private const PAPER_WIDTH_INCHES = 8.5;

    private const PAPER_HEIGHT_INCHES = 11.0;

    public function __construct(
        private readonly GenerateResumeDocument $documentGenerator,
    ) {}

    public function generate(ResumeVariant $variant): string
    {
        $html = view('resume.print', [
            'document' => $this->documentGenerator->generate($variant),
        ])->render();

        $browser = (new BrowserFactory)->createBrowser(['headless' => true]);

        try {
            $page = $browser->createPage();
            $page->setHtml($html);

            $pdf = $page->pdf([
                'printBackground' => true,
                'displayHeaderFooter' => false,
                'paperWidth' => self::PAPER_WIDTH_INCHES,
                'paperHeight' => self::PAPER_HEIGHT_INCHES,
                'marginTop' => 0,
                'marginBottom' => 0,
                'marginLeft' => 0,
                'marginRight' => 0,
                'preferCSSPageSize' => true,
            ]);

            $bytes = base64_decode($pdf->getBase64(), true);

            return $bytes === false ? '' : $bytes;
        } finally {
            $browser->close();
        }
    }
}
