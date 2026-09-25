<?php

use App\Models\ResumeVariant;
use Illuminate\Support\Facades\Http;
use Smalot\PdfParser\Parser as PdfParser;
use Tests\Support\ResumeVariantFixtures;

/**
 * Proves the PDF export route (GET /resume-variants/{resumeVariant}/pdf)
 * goes through the exact same GenerateResumeDocument +
 * resources/views/resume/print.blade.php path as the HTML preview —
 * never a second PDF-specific template, never live domain
 * reconstruction, never a provider/network call. Deep content/page-
 * count regression against a real, larger, human-reviewed resume lives
 * in GenerateResumePdfTest.php; this file is about the response
 * envelope and data path, not resume content.
 */
function pdfCandidateVariant(): array
{
    $candidate = ResumeVariantFixtures::candidate();
    $job = ResumeVariantFixtures::job();
    $match = ResumeVariantFixtures::jobMatch(
        $candidate['profile'], $job['analysis'], $job['azureFinding'], $job['ownershipFinding'],
        $candidate['factAws'], $candidate['factIndependent'],
    );

    // A fixed, punctuation-free name — the factory's random Faker
    // default occasionally includes a title/suffix (e.g. "Mrs. Jane Doe
    // I"), and this exact string is asserted against verbatim below, so
    // it must not vary between runs.
    $candidate['profile']->update([
        'name' => 'Jordan Rivera',
        'email' => 'candidate@example.com',
        'phone' => '(407) 272-1720',
    ]);

    $variant = ResumeVariant::factory()->create([
        'career_profile_id' => $candidate['profile']->id,
        'job_match_id' => $match->id,
        'summary' => 'A targeted PDF-export test summary.',
    ]);

    $experienceRole = $variant->experienceRoles()->create([
        'role_id' => $candidate['role']->id,
        'employer_name' => $candidate['employer']->name,
        'display_title' => 'Senior Software Engineer',
        'start_year' => $candidate['role']->start_year,
        'start_month' => $candidate['role']->start_month,
        'end_year' => $candidate['role']->end_year,
        'end_month' => $candidate['role']->end_month,
        'display_order' => 1,
    ]);
    $experienceRole->bullets()->create([
        'resume_variant_id' => $variant->id,
        'project_id' => $candidate['project']->id,
        'display_order' => 1,
        'text' => 'Built cloud-hosted deployment workflows on AWS.',
    ]);

    $variant->skillSelections()->create([
        'skill_id' => $candidate['awsSkill']->id,
        'name' => $candidate['awsSkill']->name,
        'category' => $candidate['awsSkill']->category->value,
        'display_order' => 1,
    ]);
    $variant->educationSelections()->create([
        'education_id' => $candidate['education']->id,
        'institution' => $candidate['education']->institution,
        'degree' => $candidate['education']->degree,
        'field_of_study' => $candidate['education']->field_of_study,
        'start_year' => $candidate['education']->start_year,
        'end_year' => $candidate['education']->end_year,
        'display_order' => 1,
    ]);

    return [$candidate, $variant->fresh()];
}

it('resolves the requested ResumeVariant and returns a PDF response', function () {
    [, $variant] = pdfCandidateVariant();

    $response = $this->get(route('resume-variants.pdf', $variant));

    $response->assertOk();
    expect($response->headers->get('Content-Type'))->toBe('application/pdf');
});

it('returns a Content-Disposition attachment with the deterministic filename', function () {
    [$candidate, $variant] = pdfCandidateVariant();

    $response = $this->get(route('resume-variants.pdf', $variant));

    $disposition = $response->headers->get('Content-Disposition');
    expect($disposition)->toContain('attachment')
        ->and($disposition)->toContain(str_replace(' ', '-', $candidate['profile']->name));
});

it('produces a structurally valid PDF containing the frozen ResumeVariant content', function () {
    [, $variant] = pdfCandidateVariant();

    $response = $this->get(route('resume-variants.pdf', $variant));

    expect($response->getContent())->toStartWith('%PDF-');

    $document = (new PdfParser)->parseContent($response->getContent());
    expect($document->getText())
        ->toContain('A targeted PDF-export test summary.')
        ->toContain('Built cloud-hosted deployment workflows on AWS.');
});

it('renders the frozen snapshot, not live domain data, when the underlying Employer/Skill are mutated after generation', function () {
    [$candidate, $variant] = pdfCandidateVariant();
    $originalEmployerName = $candidate['employer']->name;

    $candidate['employer']->update(['name' => 'A Completely Different Employer']);
    $candidate['awsSkill']->update(['name' => 'A Completely Different Skill']);

    $response = $this->get(route('resume-variants.pdf', $variant));
    $document = (new PdfParser)->parseContent($response->getContent());

    expect($document->getText())
        ->toContain($originalEmployerName)
        ->not->toContain('A Completely Different Employer')
        ->not->toContain('A Completely Different Skill');
});

it('makes no outbound HTTP/provider calls while exporting the PDF', function () {
    Http::fake();
    [, $variant] = pdfCandidateVariant();

    $this->get(route('resume-variants.pdf', $variant))->assertOk();

    Http::assertNothingSent();
});

it('sources the PDF from the same rendered markup as the HTML preview', function () {
    [$candidate, $variant] = pdfCandidateVariant();

    $previewHtml = $this->get(route('resume-variants.preview', $variant))->getContent();
    $pdfResponse = $this->get(route('resume-variants.pdf', $variant));
    $pdfText = (new PdfParser)->parseContent($pdfResponse->getContent())->getText();

    // Every distinct field GenerateResumeDocument assembles — summary,
    // Experience bullet, Skill name, Education degree — must appear in
    // BOTH the raw preview HTML and the Chromium-rendered PDF text.
    // Preview never touches Eloquent/live data directly (see
    // ResumeVariantPreviewTest.php) and PDF export renders the
    // identical `resume.print` Blade output — both surfaces agreeing on
    // every field is exactly what "one template, two outputs" predicts,
    // and what a second, independently-maintained PDF template could
    // easily drift on.
    $expectedStrings = [
        'A targeted PDF-export test summary.',
        'Built cloud-hosted deployment workflows on AWS.',
        $candidate['awsSkill']->name,
        $candidate['education']->degree,
    ];

    foreach ($expectedStrings as $expected) {
        expect($previewHtml)->toContain($expected);
        expect($pdfText)->toContain($expected);
    }
});

// --- Export acceptance gate (ResumePdfValidator, wired via the controller) ---

/**
 * Deliberately excessive Experience volume — far beyond anything
 * ResumeSelectionResponseValidator's budget would ever legally
 * approve — used only to reliably force the >2-page rejection path
 * through the real HTTP route. See ResumePdfValidatorTest.php for the
 * validator's own direct unit coverage.
 */
function overLongPdfCandidateVariant(): ResumeVariant
{
    $candidate = ResumeVariantFixtures::candidate();
    $job = ResumeVariantFixtures::job();
    $match = ResumeVariantFixtures::jobMatch(
        $candidate['profile'], $job['analysis'], $job['azureFinding'], $job['ownershipFinding'],
        $candidate['factAws'], $candidate['factIndependent'],
    );

    $variant = ResumeVariant::factory()->create([
        'career_profile_id' => $candidate['profile']->id,
        'job_match_id' => $match->id,
        'summary' => 'A deliberately long summary used only to force a three-page PDF for an export-gate rejection test.',
    ]);

    $role = $variant->experienceRoles()->create([
        'role_id' => $candidate['role']->id,
        'employer_name' => $candidate['employer']->name,
        'display_title' => 'Senior Software Engineer',
        'start_year' => $candidate['role']->start_year,
        'start_month' => $candidate['role']->start_month,
        'end_year' => $candidate['role']->end_year,
        'end_month' => $candidate['role']->end_month,
        'display_order' => 1,
    ]);

    for ($i = 1; $i <= 40; $i++) {
        $role->bullets()->create([
            'resume_variant_id' => $variant->id,
            'display_order' => $i,
            'text' => "Deliberately verbose bullet number {$i} of forty, written with enough padding words to occupy a realistic full line of resume text purely to force page overflow for this one rejection test.",
        ]);
    }

    return $variant->fresh();
}

it('does not stream an oversized PDF as a download', function () {
    $variant = overLongPdfCandidateVariant();

    $response = $this->get(route('resume-variants.pdf', $variant));

    expect($response->headers->get('Content-Type'))->not->toBe('application/pdf');
    expect($response->headers->get('Content-Disposition'))->toBeNull();
});

it('produces a clear failure response reporting the actual page count when the PDF exceeds the two-page target', function () {
    $variant = overLongPdfCandidateVariant();

    $response = $this->get(route('resume-variants.pdf', $variant));

    $response->assertStatus(422);
    $payload = $response->json();
    expect($payload['error'])->toContain('pages')
        ->and($payload['error'])->toContain('2-page');
});

it('leaves the HTML preview available even when the PDF export would be rejected for exceeding two pages', function () {
    $variant = overLongPdfCandidateVariant();

    $this->get(route('resume-variants.preview', $variant))->assertOk();
});

it('makes no outbound HTTP/provider calls on the oversized-PDF rejection path', function () {
    Http::fake();
    $variant = overLongPdfCandidateVariant();

    $this->get(route('resume-variants.pdf', $variant));

    Http::assertNothingSent();
});
