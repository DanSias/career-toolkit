<?php

namespace App\Http\Controllers;

use App\Exceptions\InvalidResumePdfException;
use App\Exceptions\ResumePdfPageBudgetExceededException;
use App\Models\ResumeVariant;
use App\Support\ResumeDocument\GenerateResumePdf;
use App\Support\ResumeDocument\ResumePdfFilename;
use App\Support\ResumeDocument\ResumePdfValidator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

/**
 * Exports one ResumeVariant as a downloadable PDF, via the exact same
 * GenerateResumeDocument + resources/views/resume/print.blade.php path
 * ResumeVariantPreviewController renders as HTML — see
 * App\Support\ResumeDocument\GenerateResumePdf's own docblock. No
 * separate PDF-specific template, no permanent storage: the PDF is
 * generated fresh and streamed back on every request.
 *
 * Orchestration only: generate (GenerateResumePdf) -> validate
 * (ResumePdfValidator) -> respond. This controller owns none of the
 * rendering logic and none of the page-count policy itself — see each
 * class's own docblock. A rejected PDF is never streamed/downloaded;
 * the failure response reports the actual measured page count rather
 * than a generic message. No automatic regeneration, no provider call,
 * no mutation of the ResumeVariant on either path — a rejected export
 * leaves the persisted variant and its HTML preview completely
 * unaffected; the candidate can still preview it, just not download an
 * over-length PDF.
 */
class ResumeVariantPdfController extends Controller
{
    public function show(
        ResumeVariant $resumeVariant,
        GenerateResumePdf $generator,
        ResumePdfValidator $validator,
    ): Response|JsonResponse {
        $pdf = $generator->generate($resumeVariant);

        try {
            $validator->validate($pdf);
        } catch (ResumePdfPageBudgetExceededException $e) {
            return response()->json([
                'error' => "This resume rendered to {$e->pageCount} pages, exceeding the {$e->maxPages}-page resume export target. Reduce selected Experience bullets or the Selected Project and regenerate the resume before exporting again.",
            ], 422);
        } catch (InvalidResumePdfException $e) {
            return response()->json(['error' => 'The generated PDF could not be verified and was not downloaded.'], 422);
        }

        $filename = ResumePdfFilename::build($resumeVariant);

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }
}
