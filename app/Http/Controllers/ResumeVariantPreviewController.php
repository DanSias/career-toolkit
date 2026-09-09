<?php

namespace App\Http\Controllers;

use App\Models\ResumeVariant;
use App\Support\ResumeDocument\GenerateResumeDocument;
use Illuminate\Contracts\View\View;

/**
 * Renders the deterministic, non-Inertia print/preview view for one
 * ResumeVariant — the eventual single visual source for both the
 * browser preview and (once dompdf is installed) PDF export. Resolves
 * exactly one ResumeVariant, builds a ResumeDocument from it via
 * GenerateResumeDocument, and passes only that DTO tree to the Blade
 * template — the view never touches Eloquent models or live domain
 * data directly. See docs/domain-model.md "ResumeVariant" -> "ATS
 * Resume Renderer".
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
