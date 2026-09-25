<?php

namespace App\Http\Controllers;

use App\Models\ResumeVariant;
use App\Support\ResumeDocument\GenerateResumeDocument;
use Illuminate\Contracts\View\View;

/**
 * Renders the deterministic, non-Inertia print/preview view for one
 * ResumeVariant — the single visual source for both the browser
 * preview and PDF export (see
 * App\Http\Controllers\ResumeVariantPdfController /
 * App\Support\ResumeDocument\GenerateResumePdf, which render this exact
 * same Blade output via headless Chromium). Resolves exactly one
 * ResumeVariant, builds a ResumeDocument from it via
 * GenerateResumeDocument, and passes only that DTO tree to the Blade
 * template — the view never touches Eloquent models or live domain
 * data directly. See docs/resume-variant-generation.md "PDF export".
 *
 * Deliberately separate from ResumeVariantController, which stays
 * exactly what it already is: an Inertia/React audit and review
 * surface (citations, posture badges, visibility) — not a resume-
 * shaped document. This route renders the resume itself.
 */
class ResumeVariantPreviewController extends Controller
{
    public function show(ResumeVariant $resumeVariant, GenerateResumeDocument $generator): View
    {
        return view('resume.print', [
            'document' => $generator->generate($resumeVariant),
        ]);
    }
}
