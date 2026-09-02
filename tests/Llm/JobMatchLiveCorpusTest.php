<?php

use App\Enums\JobAnalysisFindingCategory;
use App\Enums\JobMatchCoverage;
use App\Models\JobMatch;
use App\Support\CurrentCareerProfile;
use App\Support\JobAnalysis\GenerateJobAnalysis;
use App\Support\JobMatch\GenerateJobMatch;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;

/**
 * Opt-in regression/quality evaluation of Career-Fact Matching against
 * the real, configured OpenAI provider AND the real canonical career
 * dataset — makes real API calls and costs real money, so it lives
 * outside phpunit.xml's default testsuites (see tests/Pest.php) and is
 * never part of `php artisan test`. Run explicitly:
 *
 *     vendor/bin/pest tests/Llm/JobMatchLiveCorpusTest.php
 *
 * Do NOT run this file as part of routine development — it is created
 * as infrastructure only, per the Career-Fact Matching milestone's
 * explicit "create but do not run" instruction, and requires separate,
 * explicit approval to execute.
 *
 * Each test imports the real `data/canonical-career-data.proposed.json`
 * dataset fresh (RefreshDatabase resets between tests), generates a
 * real JobAnalysis for one corpus posting, then generates a real
 * JobMatch against the real, imported CareerProfile — the same object
 * graph production code uses end to end.
 *
 * --- Corpus selection ---------------------------------------------
 *
 * sources/jobs/job-analysis-design-set.md remains the single source of
 * truth for postings — reuses jobAnalysisCorpusPosting(), defined once
 * in tests/Pest.php (always loaded, regardless of which specific file
 * or directory is targeted) rather than duplicating the parser or the
 * posting text into a new fixture.
 *
 * Of the five design-corpus postings, three are used here — Pearly,
 * Cardiff, and Vista — chosen by inspecting all five against what the
 * canonical dataset actually contains, not defaulted to any particular
 * pair:
 *
 *   - Pearly requires a "Computer Science or similar quantitative /
 *     technical / engineering degree" (none of the candidate's three
 *     degrees — MS Optics, MS Management, BS Engineering Physics — is
 *     a CS degree, so this is a genuine transferable/contextual
 *     Education-match case, not a trivial direct one); is explicitly a
 *     payments company wanting "100M+ records" accuracy-sensitive-
 *     domain experience (pulls in the Transaction Toolkit / Transaction
 *     Remediation Tooling guardrails); is "Revenue-aware" (pulls in the
 *     Nexus $25M+ visibility-vs-ownership guardrail); and requires
 *     being based in one of three named cities (a genuine
 *     not_assessable current-location example).
 *   - Cardiff explicitly asks for a link to the candidate's GitHub,
 *     makes AI-native tooling "your default way of building", and
 *     calls out "shipped solo or on a small founding team" as a
 *     nice-to-have — pulling in the RocketGate GitLab-vs-GitHub,
 *     Knowledge-Exporter-AI-mischaracterization, and independent-
 *     implementation-vs-solo guardrails together, plus the Marketing
 *     Forecasting 10-15-vs-superseded-20+ guardrail via its KPI/ROI
 *     success-measure findings.
 *   - Vista requires "Willingness to travel 35% to 50%" (a genuine
 *     not_assessable travel-willingness example — distinct from past
 *     professional travel, which the candidate's data does contain)
 *     and comfort ramping across Python, Java, Go, TypeScript, and C#
 *     — decomposed by JobAnalysis into one atomic technology finding
 *     per language, confirmed live — giving a genuine no_evidence
 *     example for Java/Go/C#, languages the canonical dataset has zero
 *     evidence for, alongside Python/TypeScript, which it does.
 *
 * Rootstock (Authorization/visa) and Intent Design were left out only
 * because Pearly and Vista already exercise the same not_assessable
 * *category* (current-location, travel-willingness) more richly
 * alongside other goals — not because their content is unsuitable; if
 * a future revision needs an explicit visa/Authorization example,
 * Rootstock is the natural addition.
 *
 * --- Guardrail-check design -----------------------------------------
 *
 * Per the architecture's live-evaluation revision (see
 * docs/job-match-generation.md), only two of the eight canonical
 * guardrails identified during design have a check specific and
 * unambiguous enough to hard-fail this test on. The rest are printed
 * as flags for human review via the inspection UI
 * (jobs/{job}/analyses/{analysis}/matches/{match}) rather than
 * asserted, to avoid the brittle-keyword-ban failure mode the
 * JobAnalysis milestone's own guardrail checks were revised away from
 * (e.g. banning the word "AI" outright would fail a *correct* sentence
 * like "Knowledge Exporter is deterministic rather than AI-based").
 *
 * Hard (assert, will fail the test):
 *   - Toolkit-vs-Remediation number conflation: a CareerFactMatch
 *     citing a Transaction Toolkit fact must never carry Transaction
 *     Remediation Tooling's numbers (43,348 / 32,000+) in its
 *     rationale — Toolkit itself has no such figures.
 *   - Marketing Forecasting 10-15-vs-superseded-20+: a CareerFactMatch
 *     citing the Marketing Forecasting hours-saved fact must never
 *     restate the superseded "20+ hours/month" figure.
 *
 * Soft (logged via Log::warning for human review, never asserted):
 *   - merchant-integration overstatement (claiming personally-written
 *     merchant integrations)
 *   - 43,348-analyzed-vs-corrected conflation
 *   - Nexus $25M+ visibility-vs-ownership conflation
 *   - RocketGate GitLab-vs-GitHub conflation
 *   - Knowledge Exporter AI-mischaracterization
 *   - independent-implementation-vs-solo overstatement
 */
beforeEach(function () {
    if (blank(config('services.openai.key'))) {
        $this->markTestSkipped('No OPENAI_API_KEY configured — skipping live JobMatch corpus evaluation.');
    }

    Artisan::call('career:import');
});

/**
 * @return array<int, array{key: string, rationale: string|null}>
 */
function jobMatchCorpusCareerFactCitations(JobMatch $match): array
{
    $match->loadMissing('findings.careerFactMatches.careerFact');

    return $match->findings
        ->flatMap(fn ($finding) => $finding->careerFactMatches)
        ->map(fn ($cfm) => ['key' => $cfm->careerFact->key, 'rationale' => $cfm->rationale])
        ->all();
}

/**
 * Prints a non-fatal flag for a human reviewing the live-eval run —
 * never fails the test. See "Guardrail-check design" above for why
 * these specific traps are soft rather than hard checks.
 */
function flagJobMatchGuardrail(string $trap, string $factKey, ?string $rationale): void
{
    Log::warning("[JobMatch live-eval guardrail flag] {$trap}", [
        'career_fact_key' => $factKey,
        'rationale' => $rationale,
    ]);
}

function jobMatchCorpusFindingContaining(JobMatch $match, callable $predicate): ?object
{
    $match->loadMissing('findings.jobAnalysisFinding');

    return $match->findings->first(
        fn ($finding) => $predicate($finding->jobAnalysisFinding)
    );
}

/**
 * Finds the technology finding for one named programming language,
 * matched by statement text rather than a hardcoded finding id — ids
 * are freshly generated on every real run, so a hardcoded id would
 * silently stop matching the moment the corpus is regenerated. The
 * live JobAnalysis run confirmed each named language in a multi-language
 * requirement is decomposed into its own atomic `technology` finding
 * (e.g. "Python is a programming language relevant to the role's
 * multi-stack work."), never combined into one finding naming several
 * languages together — so this matches one language name per call,
 * bounded so "Java" cannot false-positive-match inside "JavaScript".
 */
function jobMatchCorpusFindingForLanguage(JobMatch $match, string $language): ?object
{
    $match->loadMissing('findings.jobAnalysisFinding');
    $pattern = '/(?<!\w)'.preg_quote($language, '/').'(?!\w)/';

    return $match->findings->first(function ($finding) use ($pattern) {
        $jaf = $finding->jobAnalysisFinding;

        return $jaf->category === JobAnalysisFindingCategory::Technology
            && preg_match($pattern, $jaf->statement) === 1;
    });
}

it('matches Pearly: genuine Education transferability, current-location not_assessable, and the Toolkit/Nexus guardrails', function () {
    $posting = jobAnalysisCorpusPosting('Pearly');
    $analysis = app(GenerateJobAnalysis::class)->generate($posting);
    $profile = CurrentCareerProfile::resolve();

    $match = app(GenerateJobMatch::class)->generate($analysis, $profile);

    // Structural sanity: completeness is already deterministically
    // enforced by JobMatchResponseValidator, but re-confirm it here as
    // a live smoke check, and confirm at least one finding found real
    // support — a real, relevant profile against a real, relevant
    // posting should produce at least one non-trivial match.
    expect($match->findings)->toHaveCount($analysis->findings->count());
    expect($match->findings->pluck('coverage'))->toContain(JobMatchCoverage::Supported);

    // Goal: genuine Education-vs-degree matching. None of the
    // candidate's three degrees is Computer Science, so this exercises
    // transferable/contextual Education matching rather than a trivial
    // direct hit — the exact relationship chosen is left to human
    // review, only that Education support was considered at all.
    $degreeFinding = jobMatchCorpusFindingContaining(
        $match,
        fn ($f) => str_contains(strtolower($f->statement), 'degree') || str_contains($f->statement, 'Computer Science')
    );
    expect($degreeFinding)->not->toBeNull('Expected Pearly\'s degree-requirement finding to be present in the JobAnalysis.');
    $degreeFinding->loadMissing('educationMatches');
    expect($degreeFinding->educationMatches)->not->toBeEmpty();

    // Goal: a genuine not_assessable example. Current physical location
    // is explicitly outside this matcher's authorized domain.
    $locationFinding = jobMatchCorpusFindingContaining(
        $match,
        fn ($f) => str_contains($f->statement, 'New York') || str_contains($f->statement, 'Santa Barbara') || str_contains($f->statement, 'Atlanta')
    );
    expect($locationFinding)->not->toBeNull('Expected Pearly\'s location-requirement finding to be present in the JobAnalysis.');
    expect($locationFinding->coverage)->toBe(JobMatchCoverage::NotAssessable);

    $citations = jobMatchCorpusCareerFactCitations($match);

    // Hard guardrail: Toolkit-vs-Remediation number conflation.
    foreach ($citations as $citation) {
        if (in_array($citation['key'], ['rocketgate-transaction-toolkit-what-it-is', 'rocketgate-transaction-toolkit-local-first-security'], true)) {
            expect($citation['rationale'] ?? '')
                ->not->toContain('43,348')
                ->and($citation['rationale'] ?? '')->not->toContain('32,000');
        }
    }

    // Soft flags — printed for human review, never asserted.
    foreach ($citations as $citation) {
        if (str_contains($citation['key'], 'merchant-integration') || str_contains($citation['key'], 'merchant')) {
            flagJobMatchGuardrail('merchant-integration overstatement', $citation['key'], $citation['rationale']);
        }
        if ($citation['key'] === 'rocketgate-transaction-remediation-final-audit-population' && str_contains($citation['rationale'] ?? '', 'corrected')) {
            flagJobMatchGuardrail('43,348-analyzed-vs-corrected conflation', $citation['key'], $citation['rationale']);
        }
        if ($citation['key'] === 'pearson-nexus-marketing-spend-visibility') {
            flagJobMatchGuardrail('Nexus $25M+ visibility-vs-ownership — verify this frames spend as tracked/visible, not owned', $citation['key'], $citation['rationale']);
        }
    }
});

it('matches Cardiff: meaningful direct matching plus the GitLab/GitHub, Knowledge Exporter, independent-implementation, and Marketing Forecasting guardrails', function () {
    $posting = jobAnalysisCorpusPosting('Cardiff');
    $analysis = app(GenerateJobAnalysis::class)->generate($posting);
    $profile = CurrentCareerProfile::resolve();

    $match = app(GenerateJobMatch::class)->generate($analysis, $profile);

    expect($match->findings)->toHaveCount($analysis->findings->count());
    expect($match->findings->pluck('coverage'))->toContain(JobMatchCoverage::Supported);

    $citations = jobMatchCorpusCareerFactCitations($match);

    // Hard guardrail: Marketing Forecasting 10-15-vs-superseded-20+.
    foreach ($citations as $citation) {
        if ($citation['key'] === 'pearson-marketing-budget-forecast-hours-saved-per-month') {
            expect($citation['rationale'] ?? '')->not->toContain('20+');
        }
    }

    // Soft flags.
    foreach ($citations as $citation) {
        if (str_starts_with($citation['key'], 'rocketgate-') && str_contains($citation['rationale'] ?? '', 'GitHub')) {
            flagJobMatchGuardrail('RocketGate GitLab-vs-GitHub conflation', $citation['key'], $citation['rationale']);
        }
        if ($citation['key'] === 'rocketgate-knowledge-exporter-what-it-is' && str_contains($citation['rationale'] ?? '', 'AI')) {
            flagJobMatchGuardrail('Knowledge Exporter AI-mischaracterization — verify AI is not misattributed to this deterministic pipeline', $citation['key'], $citation['rationale']);
        }
        if ($citation['key'] === 'rocketgate-independently-implemented-from-team-requirements') {
            $rationale = $citation['rationale'] ?? '';
            if (str_contains($rationale, 'solo') || str_contains($rationale, 'alone') || str_contains($rationale, 'single-handedly')) {
                flagJobMatchGuardrail('independent-implementation-vs-solo overstatement — the canonical fact deliberately does not claim isolation from the team', $citation['key'], $rationale);
            }
        }
    }
});

it('matches Vista: travel-willingness not_assessable and per-language supported/no_evidence split across the multi-language requirement', function () {
    $posting = jobAnalysisCorpusPosting('Vista');
    $analysis = app(GenerateJobAnalysis::class)->generate($posting);
    $profile = CurrentCareerProfile::resolve();

    $match = app(GenerateJobMatch::class)->generate($analysis, $profile);

    expect($match->findings)->toHaveCount($analysis->findings->count());

    // Goal: a genuine not_assessable example, of the "travel
    // willingness" subtype — distinct from Pearly's "current location"
    // subtype above. Past professional travel (which the candidate's
    // data does contain, via Vista's own JobAnalysis live-eval
    // counterpart) must not be treated as proof of present willingness.
    $travelFinding = jobMatchCorpusFindingContaining(
        $match,
        fn ($f) => $f->category === JobAnalysisFindingCategory::Travel
    );
    expect($travelFinding)->not->toBeNull('Expected Vista\'s posting to produce a Travel-category finding.');
    expect($travelFinding->coverage)->toBe(JobMatchCoverage::NotAssessable);

    // Goal: a genuine no_evidence example, isolated per language. The
    // posting's multi-language "comfort ramping" requirement is
    // decomposed by JobAnalysis into one atomic technology finding per
    // named language (Python, Java, Go, TypeScript, C#) — not one
    // combined finding — so each is checked independently rather than
    // searching for a single finding whose statement happens to name
    // several languages at once. The canonical dataset has evidence for
    // Python and TypeScript but none for Java, Go, or C#.
    $pythonFinding = jobMatchCorpusFindingForLanguage($match, 'Python');
    $typescriptFinding = jobMatchCorpusFindingForLanguage($match, 'TypeScript');
    $javaFinding = jobMatchCorpusFindingForLanguage($match, 'Java');
    $goFinding = jobMatchCorpusFindingForLanguage($match, 'Go');
    $cSharpFinding = jobMatchCorpusFindingForLanguage($match, 'C#');

    expect($pythonFinding)->not->toBeNull('Expected a Python technology finding in Vista\'s JobAnalysis.')
        ->and($typescriptFinding)->not->toBeNull('Expected a TypeScript technology finding in Vista\'s JobAnalysis.')
        ->and($javaFinding)->not->toBeNull('Expected a Java technology finding in Vista\'s JobAnalysis.')
        ->and($goFinding)->not->toBeNull('Expected a Go technology finding in Vista\'s JobAnalysis.')
        ->and($cSharpFinding)->not->toBeNull('Expected a C# technology finding in Vista\'s JobAnalysis.');

    expect($pythonFinding->coverage)->toBe(JobMatchCoverage::Supported)
        ->and($typescriptFinding->coverage)->toBe(JobMatchCoverage::Supported)
        ->and($javaFinding->coverage)->toBe(JobMatchCoverage::NoEvidence)
        ->and($goFinding->coverage)->toBe(JobMatchCoverage::NoEvidence)
        ->and($cSharpFinding->coverage)->toBe(JobMatchCoverage::NoEvidence);
});
