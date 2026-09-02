<?php

use App\Enums\JobAnalysisFindingCategory;
use App\Enums\JobAnalysisRequirementStrength;
use App\Models\JobPosting;
use App\Support\JobAnalysis\GenerateJobAnalysis;

/**
 * Opt-in regression evaluation against the real, configured OpenAI
 * provider — makes real API calls and costs real money, so it lives
 * outside phpunit.xml's default testsuites (see tests/Pest.php) and is
 * never part of `php artisan test`. Run explicitly:
 *
 *     vendor/bin/pest tests/Llm
 *
 * sources/jobs/job-analysis-design-set.md is the single source of
 * truth for the five postings — parsed fresh from that file below,
 * never duplicated into a fixture. Each test regression-checks one of
 * the five specific extraction failure modes the JobAnalysis design was
 * built to avoid; everything else about analysis quality is left to
 * human review via the inspection UI (jobs/{job}/analyses/{analysis}),
 * not asserted here.
 */

/**
 * @return array<int, array{company: string, title: string, description: string}>
 */
function jobAnalysisCorpusPostings(): array
{
    $path = base_path('sources/jobs/job-analysis-design-set.md');
    $contents = file_get_contents($path);

    if ($contents === false) {
        throw new RuntimeException("Could not read corpus file at [{$path}].");
    }

    $sections = preg_split('/^## /m', $contents);
    array_shift($sections); // the file's leading title/intro, before the first posting

    return array_map(function (string $section): array {
        [$headingLine, $rest] = explode("\n", $section, 2);
        [$company, $title] = array_map('trim', explode('—', $headingLine, 2));

        // Everything from the heading down to the next top-level "---"
        // divider (or end of file, for the last posting) is that
        // posting's captured source material — Source/Location/
        // Compensation header lines included, exactly as a human
        // copy-pasting a real listing would capture it. Only the
        // optional "### Original Job Description" subheading (present
        // on some but not all entries) is dropped, since it's this
        // file's own organizing label, not posting content.
        $body = preg_replace('/\n---\s*$/', '', rtrim($rest)) ?? rtrim($rest);
        $body = preg_replace('/^###\s*Original Job Description\s*$/mi', '', $body) ?? $body;

        return ['company' => $company, 'title' => $title, 'description' => trim($body)];
    }, $sections);
}

function jobAnalysisCorpusPosting(string $companyPrefix): JobPosting
{
    $postings = jobAnalysisCorpusPostings();
    $match = collect($postings)->firstWhere(fn (array $p) => str_starts_with($p['company'], $companyPrefix));

    if ($match === null) {
        throw new RuntimeException("No corpus posting found for company starting with [{$companyPrefix}].");
    }

    // The corpus captures each posting as one verbatim blob — location
    // isn't parsed out as its own structured field, it's already
    // embedded wherever the source itself states it (or doesn't) inside
    // `description`. Explicitly nulling it here is required: without
    // it, JobPostingFactory's default (`fake()->city()`) silently
    // injects a random fictitious city into the prompt's separate
    // `Location:` line — the model then faithfully reports that
    // fabricated value back as evidence, which correctly (but
    // misleadingly) fails verification against `description`, since it
    // never appears there. See docs/job-analysis-generation.md.
    return JobPosting::factory()->create([...$match, 'location' => null]);
}

beforeEach(function () {
    if (blank(config('services.openai.key'))) {
        $this->markTestSkipped('No OPENAI_API_KEY configured — skipping live five-posting corpus evaluation.');
    }
});

it('preserves both conflicting location signals for Pearly rather than collapsing the contradiction', function () {
    $posting = jobAnalysisCorpusPosting('Pearly');
    $analysis = app(GenerateJobAnalysis::class)->generate($posting);

    $excerpts = $analysis->findings->flatMap->evidence->pluck('excerpt')->implode(' | ');

    expect($excerpts)->toContain('Remote')
        ->and($excerpts)->toContain('Based in New York');
});

it('lands Rootstock ERP knowledge explicitly as not_required rather than silence', function () {
    $posting = jobAnalysisCorpusPosting('Rootstock');
    $analysis = app(GenerateJobAnalysis::class)->generate($posting);

    $notRequiredErpFinding = $analysis->findings
        ->where('requirement_strength', JobAnalysisRequirementStrength::NotRequired)
        ->first(fn ($finding) => str_contains($finding->evidence->pluck('excerpt')->implode(' '), 'ERP'));

    expect($notRequiredErpFinding)->not->toBeNull();
});

it('keeps Cardiff\'s named AI-development tooling specific rather than flattened into generic AI experience', function () {
    $posting = jobAnalysisCorpusPosting('Cardiff');
    $analysis = app(GenerateJobAnalysis::class)->generate($posting);

    $excerpts = $analysis->findings->flatMap->evidence->pluck('excerpt')->implode(' | ');
    $namedTools = ['Claude Code', 'Cursor', 'Codex', 'Devin'];

    expect(collect($namedTools)->contains(fn (string $tool) => str_contains($excerpts, $tool)))->toBeTrue();
});

it('retains multiple evidence occurrences for Vista\'s 35-50% travel requirement', function () {
    $posting = jobAnalysisCorpusPosting('Vista');
    $analysis = app(GenerateJobAnalysis::class)->generate($posting);

    $travelFinding = $analysis->findings->first(
        fn ($finding) => $finding->category === JobAnalysisFindingCategory::Travel
    );

    expect($travelFinding)->not->toBeNull()
        ->and($travelFinding->evidence->count())->toBeGreaterThanOrEqual(2);
});

it('does not blanket-propagate required onto sub-detail findings nested beneath the Agentic Workflows & Memory Systems heading for Intent Design', function () {
    $posting = jobAnalysisCorpusPosting('Intent Design');
    $analysis = app(GenerateJobAnalysis::class)->generate($posting);

    // The posting's "Minimum Requirements" list includes one bullet,
    // "Agentic Workflows & Memory Systems", which is itself only a
    // topic label — everything after it is a differently-formatted
    // block of roughly nine elaborating sub-techniques (Stateful
    // Orchestration, Context & Memory Engineering, Tool Call
    // Management, Vector & Relational Storage, Document Persistence,
    // Hybrid RAG Pipelines, Granular Tracing, Automated Evals, Core
    // Backend Development), none of which the posting independently
    // states as its own mandatory item.
    //
    // A keyword-based check (looking for "Agentic"/"Memory" in evidence
    // text) is too coarse: most of those sub-technique bullets contain
    // neither word (e.g. "Granular Tracing," "Document Persistence"),
    // so it would silently miss most of the findings this regression
    // exists to catch — confirmed against a real generation, where a
    // keyword filter caught only 1 of 9 sub-detail findings actually
    // marked required. Instead, identify every finding whose evidence
    // is drawn entirely from the text following that heading —
    // regardless of which specific words appear in it — and assert
    // none of those sub-detail findings is required. A finding whose
    // evidence IS the heading line itself is exempt: a top-level
    // capability presented directly as a minimum requirement may
    // legitimately be required — it's the elaborating detail beneath it
    // that must never inherit that status automatically.
    $marker = 'Agentic Workflows & Memory Systems';
    $headingPosition = strpos($posting->description, $marker);
    expect($headingPosition)->not->toBeFalse();

    $detailBlock = substr($posting->description, $headingPosition + strlen($marker));

    $subDetailFindings = $analysis->findings->filter(function ($finding) use ($detailBlock, $marker) {
        $excerpts = $finding->evidence->pluck('excerpt');

        return $excerpts->isNotEmpty()
            && $excerpts->every(fn (string $excerpt) => $excerpt !== $marker && str_contains($detailBlock, $excerpt));
    });

    // Vacuously true if the model produces no findings from this block
    // at all — the regression this guards against is specifically
    // "blanket propagation onto sub-details," not "must produce a
    // finding about it."
    expect($subDetailFindings->every(
        fn ($finding) => $finding->requirement_strength !== JobAnalysisRequirementStrength::Required
    ))->toBeTrue();
});
