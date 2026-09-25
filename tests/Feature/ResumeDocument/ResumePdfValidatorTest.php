<?php

use App\Exceptions\InvalidResumePdfException;
use App\Exceptions\ResumePdfPageBudgetExceededException;
use App\Models\ResumeVariant;
use App\Support\ResumeDocument\GenerateResumePdf;
use App\Support\ResumeDocument\ResumePdfValidator;
use Tests\Support\PearlyResumeVariantFixture;
use Tests\Support\ResumeVariantFixtures;

/**
 * The final artifact-acceptance gate for exported resume PDFs — see
 * App\Support\ResumeDocument\ResumePdfValidator's own docblock for why
 * it is deliberately separate from both GenerateResumePdf (rendering)
 * and GenerateResumePdfTest.php's exact Pearly page-count regression
 * (a renderer/layout tripwire for one specific fixture, not a general
 * acceptance rule). This file proves the general <=2-page rule itself,
 * independent of any one fixture's expected count.
 */
function minimalOnePageVariant(): ResumeVariant
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
        'summary' => 'A short, minimal summary for a one-page PDF validator test.',
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
    $role->bullets()->create([
        'resume_variant_id' => $variant->id,
        'display_order' => 1,
        'text' => 'Built cloud-hosted deployment workflows on AWS.',
    ]);

    return $variant->fresh();
}

/**
 * Deliberately excessive Experience volume — far beyond anything the
 * current ResumeSelectionResponseValidator budget would ever legally
 * approve — used only to reliably force a >2-page PDF for this
 * validator's own rejection test. Not a claim about what Selection can
 * produce; see docs/resume-variant-generation.md "PDF export".
 */
function deliberatelyOverLongVariant(): ResumeVariant
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
        'summary' => 'A deliberately long summary used only to force a three-page PDF for a validator rejection test, far beyond anything the deterministic Selection budget would ever approve in real generation.',
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

it('accepts a valid 1-page PDF', function () {
    $pdf = app(GenerateResumePdf::class)->generate(minimalOnePageVariant());

    expect(fn () => (new ResumePdfValidator)->validate($pdf))->not->toThrow(Throwable::class);
});

it('accepts a valid 2-page PDF (the real, human-reviewed Pearly fixture)', function () {
    $pdf = app(GenerateResumePdf::class)->generate(PearlyResumeVariantFixture::generate());

    expect(fn () => (new ResumePdfValidator)->validate($pdf))->not->toThrow(Throwable::class);
});

it('rejects a valid 3-page PDF and exposes the actual measured page count', function () {
    $pdf = app(GenerateResumePdf::class)->generate(deliberatelyOverLongVariant());

    try {
        (new ResumePdfValidator)->validate($pdf);
        test()->fail('Expected ResumePdfPageBudgetExceededException to be thrown.');
    } catch (ResumePdfPageBudgetExceededException $e) {
        expect($e->pageCount)->toBeGreaterThan(2)
            ->and($e->maxPages)->toBe(2)
            ->and($e->getMessage())->toContain((string) $e->pageCount)
            ->and($e->getMessage())->toContain('2');
    }
});

it('rejects malformed/unparseable PDF input cleanly, never treating it as acceptable', function () {
    expect(fn () => (new ResumePdfValidator)->validate('this is not a pdf at all'))
        ->toThrow(InvalidResumePdfException::class);
});

it('rejects empty input cleanly', function () {
    expect(fn () => (new ResumePdfValidator)->validate(''))
        ->toThrow(InvalidResumePdfException::class);
});
