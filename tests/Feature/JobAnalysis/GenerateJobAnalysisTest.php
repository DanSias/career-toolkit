<?php

use App\Contracts\GeneratesJobAnalysis;
use App\Enums\JobAnalysisFindingCategory;
use App\Exceptions\InvalidJobAnalysisResponseException;
use App\Exceptions\JobAnalysisProviderException;
use App\Models\JobAnalysis;
use App\Models\JobAnalysisFinding;
use App\Models\JobAnalysisFindingEvidence;
use App\Models\JobPosting;
use App\Support\JobAnalysis\GenerateJobAnalysis;
use App\Support\JobAnalysis\JobAnalysisProviderResponse;
use Illuminate\Database\QueryException;
use Tests\Support\FakeJobAnalysisProvider;
use Tests\Support\JobAnalysisFixtures;

/**
 * Binds a FakeJobAnalysisProvider into the container and resolves the
 * real orchestrator — the same object graph production code uses, minus
 * the real network call. Nothing in this file makes a real OpenAI call.
 */
function fakeProvider(): FakeJobAnalysisProvider
{
    $fake = new FakeJobAnalysisProvider;
    app()->instance(GeneratesJobAnalysis::class, $fake);

    return $fake;
}

function generator(): GenerateJobAnalysis
{
    return app(GenerateJobAnalysis::class);
}

function postingWithDescription(string $description = JobAnalysisFixtures::DESCRIPTION): JobPosting
{
    return JobPosting::factory()->create(['description' => $description]);
}

it('persists the full graph from a valid provider response', function () {
    $posting = postingWithDescription();
    fakeProvider()->willReturn(new JobAnalysisProviderResponse(
        provider: 'openai',
        model: 'gpt-5.6-test',
        structuredContent: JobAnalysisFixtures::validPayload(),
    ));

    $analysis = generator()->generate($posting);

    expect(JobAnalysis::count())->toBe(1)
        ->and(JobAnalysisFinding::count())->toBe(3)
        ->and(JobAnalysisFindingEvidence::count())->toBe(4)
        ->and($analysis->findings)->toHaveCount(3);
});

it('sends the provider only company, title, location, and description', function () {
    $posting = JobPosting::factory()->create([
        'company' => 'Acme Corp',
        'title' => 'Staff Engineer',
        'location' => 'Remote',
        'description' => JobAnalysisFixtures::DESCRIPTION,
    ]);
    $fake = fakeProvider()->willReturn(new JobAnalysisProviderResponse(
        provider: 'openai',
        model: 'gpt-5.6-test',
        structuredContent: JobAnalysisFixtures::validPayload(),
    ));

    generator()->generate($posting);

    expect($fake->capturedUserPrompt)
        ->toContain('Acme Corp')
        ->toContain('Staff Engineer')
        ->toContain('Remote')
        ->toContain(JobAnalysisFixtures::DESCRIPTION)
        ->not->toContain('CareerFact')
        ->not->toContain('Skill')
        ->not->toContain('candidate');
});

it('persists correct schema/prompt/model generation metadata', function () {
    $posting = postingWithDescription();
    fakeProvider()->willReturn(new JobAnalysisProviderResponse(
        provider: 'openai',
        model: 'gpt-5.6-2026-09-01',
        structuredContent: JobAnalysisFixtures::validPayload(),
    ));

    $analysis = generator()->generate($posting);

    expect($analysis->schema_version)->toBe('1.0')
        ->and($analysis->prompt_version)->toBe('job-analysis-v2')
        ->and($analysis->generated_by)->toBe('openai:gpt-5.6-2026-09-01')
        ->and($analysis->generated_at)->not->toBeNull()
        ->and($analysis->raw_response)->toBeArray();
});

it('rejects an unsupported enum value and persists nothing', function () {
    $posting = postingWithDescription();
    $payload = JobAnalysisFixtures::validPayload();
    $payload['findings'][0]['category'] = 'not_a_real_category';
    fakeProvider()->willReturn(new JobAnalysisProviderResponse('openai', 'gpt-5.6-test', $payload));

    expect(fn () => generator()->generate($posting))
        ->toThrow(InvalidJobAnalysisResponseException::class);
    expect(JobAnalysis::count())->toBe(0);
});

it('rejects a response missing required fields and persists nothing', function () {
    $posting = postingWithDescription();
    $payload = JobAnalysisFixtures::validPayload();
    unset($payload['role_summary']);
    fakeProvider()->willReturn(new JobAnalysisProviderResponse('openai', 'gpt-5.6-test', $payload));

    expect(fn () => generator()->generate($posting))
        ->toThrow(InvalidJobAnalysisResponseException::class);
    expect(JobAnalysis::count())->toBe(0);
});

it('rejects an empty findings list and persists nothing', function () {
    $posting = postingWithDescription();
    $payload = JobAnalysisFixtures::validPayload();
    $payload['findings'] = [];
    fakeProvider()->willReturn(new JobAnalysisProviderResponse('openai', 'gpt-5.6-test', $payload));

    expect(fn () => generator()->generate($posting))
        ->toThrow(InvalidJobAnalysisResponseException::class);
    expect(JobAnalysis::count())->toBe(0);
});

it('rejects an invalid experience range and persists nothing', function () {
    $posting = postingWithDescription();
    $payload = JobAnalysisFixtures::validPayload();
    $payload['findings'][0]['years_experience_min'] = 8;
    $payload['findings'][0]['years_experience_max'] = 2;
    fakeProvider()->willReturn(new JobAnalysisProviderResponse('openai', 'gpt-5.6-test', $payload));

    expect(fn () => generator()->generate($posting))
        ->toThrow(InvalidJobAnalysisResponseException::class);
    expect(JobAnalysis::count())->toBe(0);
});

it('persists a valid single evidence excerpt', function () {
    $posting = postingWithDescription();
    $payload = JobAnalysisFixtures::validPayload();
    $payload['findings'] = [$payload['findings'][0]]; // the single-evidence finding only
    fakeProvider()->willReturn(new JobAnalysisProviderResponse('openai', 'gpt-5.6-test', $payload));

    $analysis = generator()->generate($posting);

    expect($analysis->findings)->toHaveCount(1)
        ->and($analysis->findings->first()->evidence)->toHaveCount(1);
});

it('persists repeated evidence on the same finding', function () {
    $posting = postingWithDescription();
    $payload = JobAnalysisFixtures::validPayload();
    fakeProvider()->willReturn(new JobAnalysisProviderResponse('openai', 'gpt-5.6-test', $payload));

    $analysis = generator()->generate($posting);

    $travelFinding = $analysis->findings->firstWhere('category', JobAnalysisFindingCategory::Travel);
    expect($travelFinding->evidence)->toHaveCount(2);
});

it('persists a whitespace-normalized evidence excerpt', function () {
    $posting = postingWithDescription();
    $payload = JobAnalysisFixtures::validPayload();
    $payload['findings'][0]['evidence'][0]['excerpt'] =
        "Minimum 5 years\n   of backend engineering  experience required.";
    fakeProvider()->willReturn(new JobAnalysisProviderResponse('openai', 'gpt-5.6-test', $payload));

    $analysis = generator()->generate($posting);

    expect(JobAnalysis::count())->toBe(1)
        ->and($analysis->findings->first()->evidence->first()->excerpt)
        ->toBe("Minimum 5 years\n   of backend engineering  experience required.");
});

it('rejects the complete analysis when any evidence excerpt is unverifiable', function () {
    $posting = postingWithDescription();
    $payload = JobAnalysisFixtures::validPayload();
    $payload['findings'][0]['evidence'][0]['excerpt'] = 'This was never in the posting.';
    fakeProvider()->willReturn(new JobAnalysisProviderResponse('openai', 'gpt-5.6-test', $payload));

    expect(fn () => generator()->generate($posting))
        ->toThrow(InvalidJobAnalysisResponseException::class);
    expect(JobAnalysis::count())->toBe(0)
        ->and(JobAnalysisFinding::count())->toBe(0)
        ->and(JobAnalysisFindingEvidence::count())->toBe(0);
});

it('rejects the complete analysis when one excerpt among otherwise-valid findings is unverifiable', function () {
    $posting = postingWithDescription();
    $payload = JobAnalysisFixtures::validPayload();
    // Only the SECOND evidence entry of the SECOND finding is corrupted —
    // findings[0] and the rest of findings[1]/findings[2] are all valid.
    $payload['findings'][1]['evidence'][1]['excerpt'] = 'Fabricated — not in the posting.';
    fakeProvider()->willReturn(new JobAnalysisProviderResponse('openai', 'gpt-5.6-test', $payload));

    expect(fn () => generator()->generate($posting))
        ->toThrow(InvalidJobAnalysisResponseException::class);
    expect(JobAnalysis::count())->toBe(0);
});

it('rolls back the entire graph when a database exception occurs during persistence', function () {
    // Simulates a genuine DB-layer failure (rather than an application
    // validation failure) by deleting the parent JobPosting out from
    // under an in-memory reference before persistence runs, so the
    // first insert (JobAnalysis's job_posting_id foreign key) fails at
    // the database layer. DB::transaction() must roll back regardless
    // of what has or hasn't been inserted yet.
    $posting = postingWithDescription();
    fakeProvider()->willReturn(new JobAnalysisProviderResponse(
        provider: 'openai',
        model: 'gpt-5.6-test',
        structuredContent: JobAnalysisFixtures::validPayload(),
    ));

    JobPosting::whereKey($posting->id)->delete();

    expect(fn () => generator()->generate($posting))->toThrow(QueryException::class);
    expect(JobAnalysis::count())->toBe(0)
        ->and(JobAnalysisFinding::count())->toBe(0)
        ->and(JobAnalysisFindingEvidence::count())->toBe(0);
});

it('creates a new independent snapshot on every successful generation, leaving prior snapshots unchanged', function () {
    $posting = postingWithDescription();
    fakeProvider()->willReturn(new JobAnalysisProviderResponse('openai', 'gpt-5.6-test', JobAnalysisFixtures::validPayload()));

    $first = generator()->generate($posting);

    $secondPayload = JobAnalysisFixtures::validPayload();
    $secondPayload['role_summary'] = 'A different, later re-analysis of the same posting.';
    fakeProvider()->willReturn(new JobAnalysisProviderResponse('openai', 'gpt-5.6-test', $secondPayload));

    $second = generator()->generate($posting);

    expect(JobAnalysis::count())->toBe(2)
        ->and($second->id)->not->toBe($first->id)
        ->and(JobAnalysis::find($first->id)->role_summary)->toBe($first->role_summary)
        ->and(JobAnalysis::find($second->id)->role_summary)->toBe('A different, later re-analysis of the same posting.');
});

it('propagates a provider failure without persisting anything', function () {
    $posting = postingWithDescription();
    fakeProvider()->willFail(new JobAnalysisProviderException('Simulated provider failure.'));

    expect(fn () => generator()->generate($posting))
        ->toThrow(JobAnalysisProviderException::class);
    expect(JobAnalysis::count())->toBe(0);
});
