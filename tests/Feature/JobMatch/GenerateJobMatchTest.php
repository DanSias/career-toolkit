<?php

use App\Contracts\GeneratesJobMatch;
use App\Enums\Visibility;
use App\Exceptions\InvalidJobMatchResponseException;
use App\Exceptions\JobMatchProviderException;
use App\Models\CareerFactMatch;
use App\Models\EducationMatch;
use App\Models\JobMatch;
use App\Models\JobMatchFinding;
use App\Support\JobMatch\CandidatePayloadBuilder;
use App\Support\JobMatch\GenerateJobMatch;
use App\Support\JobMatch\JobMatchProviderResponse;
use App\Support\JobMatch\JobPayloadBuilder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\FakeJobMatchProvider;
use Tests\Support\JobMatchFixtures;

/**
 * Binds a FakeJobMatchProvider into the container and resolves the real
 * orchestrator — the same object graph production code uses, minus the
 * real network call. Nothing in this file makes a real OpenAI call.
 */
function fakeJobMatchProvider(): FakeJobMatchProvider
{
    $fake = new FakeJobMatchProvider;
    app()->instance(GeneratesJobMatch::class, $fake);

    return $fake;
}

function jobMatchGenerator(): GenerateJobMatch
{
    return app(GenerateJobMatch::class);
}

it('persists the full graph from a valid provider response', function () {
    ['profile' => $profile, 'factOne' => $factOne, 'education' => $education] = JobMatchFixtures::candidate();
    ['analysis' => $analysis, 'findingOne' => $findingOne, 'findingTwo' => $findingTwo] = JobMatchFixtures::job();

    fakeJobMatchProvider()->willReturn(new JobMatchProviderResponse(
        provider: 'openai',
        model: 'gpt-5.6-test',
        structuredContent: JobMatchFixtures::validResponse($findingOne, $findingTwo, $factOne, $education),
    ));

    $match = jobMatchGenerator()->generate($analysis, $profile);

    expect(JobMatch::count())->toBe(1)
        ->and(JobMatchFinding::count())->toBe(2)
        ->and(CareerFactMatch::count())->toBe(1)
        ->and(EducationMatch::count())->toBe(1)
        ->and($match->findings)->toHaveCount(2);
});

it('persists correct schema/prompt/model generation metadata', function () {
    ['profile' => $profile, 'factOne' => $factOne, 'education' => $education] = JobMatchFixtures::candidate();
    ['analysis' => $analysis, 'findingOne' => $findingOne, 'findingTwo' => $findingTwo] = JobMatchFixtures::job();

    fakeJobMatchProvider()->willReturn(new JobMatchProviderResponse(
        provider: 'openai',
        model: 'gpt-5.6-2026-09-01',
        structuredContent: JobMatchFixtures::validResponse($findingOne, $findingTwo, $factOne, $education),
    ));

    $match = jobMatchGenerator()->generate($analysis, $profile);

    expect($match->schema_version)->toBe('1.0')
        ->and($match->prompt_version)->toBe('job-match-v3')
        ->and($match->generated_by)->toBe('openai:gpt-5.6-2026-09-01')
        ->and($match->generated_at)->not->toBeNull()
        ->and($match->raw_response)->toBeArray();
});

it('freezes the exact normalized candidate+job payload into input_snapshot', function () {
    ['profile' => $profile, 'factOne' => $factOne, 'education' => $education] = JobMatchFixtures::candidate();
    ['analysis' => $analysis, 'findingOne' => $findingOne, 'findingTwo' => $findingTwo] = JobMatchFixtures::job();

    fakeJobMatchProvider()->willReturn(new JobMatchProviderResponse(
        provider: 'openai',
        model: 'gpt-5.6-test',
        structuredContent: JobMatchFixtures::validResponse($findingOne, $findingTwo, $factOne, $education),
    ));

    $match = jobMatchGenerator()->generate($analysis, $profile);

    $expectedCandidate = (new CandidatePayloadBuilder)->build($profile);
    $expectedJob = (new JobPayloadBuilder)->build($analysis);

    expect($match->input_snapshot['candidate'])->toBe($expectedCandidate)
        ->and($match->input_snapshot['job'])->toBe($expectedJob);
});

it('input_snapshot survives a later edit to the live CareerFact unchanged', function () {
    ['profile' => $profile, 'factOne' => $factOne, 'education' => $education] = JobMatchFixtures::candidate();
    ['analysis' => $analysis, 'findingOne' => $findingOne, 'findingTwo' => $findingTwo] = JobMatchFixtures::job();

    fakeJobMatchProvider()->willReturn(new JobMatchProviderResponse(
        provider: 'openai',
        model: 'gpt-5.6-test',
        structuredContent: JobMatchFixtures::validResponse($findingOne, $findingTwo, $factOne, $education),
    ));

    $match = jobMatchGenerator()->generate($analysis, $profile);
    $originalStatement = collect($match->input_snapshot['candidate']['career_facts'])->firstWhere('key', $factOne->key)['statement'];

    // Directly mutate the live CareerFact's statement at the DB layer,
    // bypassing the immutability-adjacent conventions elsewhere in this
    // app — CareerFacts themselves are editable canonical data, unlike
    // JobAnalysis/JobMatch snapshots.
    DB::table('career_facts')->where('id', $factOne->id)->update(['statement' => 'A completely different, later-edited statement.']);

    $match->refresh();
    $frozenStatement = collect($match->input_snapshot['candidate']['career_facts'])->firstWhere('key', $factOne->key)['statement'];

    expect($frozenStatement)->toBe($originalStatement)
        ->and($frozenStatement)->not->toBe('A completely different, later-edited statement.');
});

it('rejects a validation failure and persists zero JobMatch rows', function () {
    ['profile' => $profile, 'factOne' => $factOne, 'education' => $education] = JobMatchFixtures::candidate();
    ['analysis' => $analysis, 'findingOne' => $findingOne, 'findingTwo' => $findingTwo] = JobMatchFixtures::job();

    $badResponse = JobMatchFixtures::validResponse($findingOne, $findingTwo, $factOne, $education);
    $badResponse['findings'][0]['coverage'] = 'definitely_hired'; // not a real enum value

    fakeJobMatchProvider()->willReturn(new JobMatchProviderResponse('openai', 'gpt-5.6-test', $badResponse));

    expect(fn () => jobMatchGenerator()->generate($analysis, $profile))
        ->toThrow(InvalidJobMatchResponseException::class);
    expect(JobMatch::count())->toBe(0);
});

it('propagates a provider failure and persists zero JobMatch rows', function () {
    ['profile' => $profile] = JobMatchFixtures::candidate();
    ['analysis' => $analysis] = JobMatchFixtures::job();

    fakeJobMatchProvider()->willFail(new JobMatchProviderException('Simulated provider failure.'));

    expect(fn () => jobMatchGenerator()->generate($analysis, $profile))
        ->toThrow(JobMatchProviderException::class);
    expect(JobMatch::count())->toBe(0);
});

it('rolls back the entire graph on a database failure during persistence', function () {
    ['profile' => $profile, 'factOne' => $factOne, 'education' => $education] = JobMatchFixtures::candidate();
    ['analysis' => $analysis, 'findingOne' => $findingOne, 'findingTwo' => $findingTwo] = JobMatchFixtures::job();

    fakeJobMatchProvider()->willReturn(new JobMatchProviderResponse(
        provider: 'openai',
        model: 'gpt-5.6-test',
        structuredContent: JobMatchFixtures::validResponse($findingOne, $findingTwo, $factOne, $education),
    ));

    // Simulates a genuine DB-layer failure partway THROUGH persistence
    // — after JobMatch and JobMatchFinding rows have already been
    // inserted within the transaction, but before CareerFactMatch is —
    // to prove already-inserted rows roll back too, not just that the
    // very first insert fails cleanly. Both payload builders re-query
    // the database fresh (unlike JobAnalysis, which reads an
    // already-loaded in-memory JobPosting), so deleting a parent row
    // beforehand would make payload-building/validation fail instead of
    // persistence — dropping career_fact_matches specifically avoids
    // that. SQLite DDL is transactional, so this drop is rolled back
    // with the rest of RefreshDatabase's per-test transaction; job_matches
    // and job_match_findings are left queryable afterward specifically
    // so this test can confirm they were rolled back too.
    Schema::drop('career_fact_matches');

    expect(fn () => jobMatchGenerator()->generate($analysis, $profile))
        ->toThrow(QueryException::class);
    expect(JobMatch::count())->toBe(0)
        ->and(JobMatchFinding::count())->toBe(0);
});

it('creates a new independent snapshot on every successful generation, leaving prior snapshots unchanged', function () {
    ['profile' => $profile, 'factOne' => $factOne, 'education' => $education] = JobMatchFixtures::candidate();
    ['analysis' => $analysis, 'findingOne' => $findingOne, 'findingTwo' => $findingTwo] = JobMatchFixtures::job();

    fakeJobMatchProvider()->willReturn(new JobMatchProviderResponse('openai', 'gpt-5.6-test', JobMatchFixtures::validResponse($findingOne, $findingTwo, $factOne, $education)));
    $first = jobMatchGenerator()->generate($analysis, $profile);

    $secondResponse = JobMatchFixtures::validResponse($findingOne, $findingTwo, $factOne, $education);
    $secondResponse['findings'][0]['coverage_rationale'] = 'A different, later re-match of the same pair.';
    fakeJobMatchProvider()->willReturn(new JobMatchProviderResponse('openai', 'gpt-5.6-test', $secondResponse));
    $second = jobMatchGenerator()->generate($analysis, $profile);

    expect(JobMatch::count())->toBe(2)
        ->and($second->id)->not->toBe($first->id)
        ->and(JobMatch::find($first->id)->findings->firstWhere('job_analysis_finding_id', $findingOne->id)->coverage_rationale)
        ->not->toBe('A different, later re-match of the same pair.');
});

it('resolves CareerFactMatch visibility live from the current CareerFact, never frozen', function () {
    ['profile' => $profile, 'factOne' => $factOne, 'education' => $education] = JobMatchFixtures::candidate();
    ['analysis' => $analysis, 'findingOne' => $findingOne, 'findingTwo' => $findingTwo] = JobMatchFixtures::job();

    $factOne->update(['visibility' => Visibility::Public]);

    fakeJobMatchProvider()->willReturn(new JobMatchProviderResponse('openai', 'gpt-5.6-test', JobMatchFixtures::validResponse($findingOne, $findingTwo, $factOne, $education)));
    $match = jobMatchGenerator()->generate($analysis, $profile);

    $cfMatch = CareerFactMatch::where('career_fact_id', $factOne->id)->firstOrFail();
    expect($cfMatch->careerFact->visibility)->toBe(Visibility::Public);

    // Tighten visibility on the live CareerFact after the match exists.
    $factOne->update(['visibility' => Visibility::Restricted]);

    expect($cfMatch->fresh()->careerFact->visibility)->toBe(Visibility::Restricted);
    // CareerFactMatch itself never stored a visibility value to begin with.
    expect($cfMatch->getAttributes())->not->toHaveKey('visibility');
});

it('never asks for or persists a computed years-of-experience figure anywhere in the match graph', function () {
    ['profile' => $profile, 'factOne' => $factOne, 'education' => $education] = JobMatchFixtures::candidate();
    ['analysis' => $analysis, 'findingOne' => $findingOne, 'findingTwo' => $findingTwo] = JobMatchFixtures::job();

    fakeJobMatchProvider()->willReturn(new JobMatchProviderResponse('openai', 'gpt-5.6-test', JobMatchFixtures::validResponse($findingOne, $findingTwo, $factOne, $education)));
    $match = jobMatchGenerator()->generate($analysis, $profile);

    $matchFinding = $match->findings->first();
    $cfMatch = $matchFinding->careerFactMatches->first();
    $eduMatch = $matchFinding->educationMatches->first();

    foreach ([$match, $matchFinding, $cfMatch, $eduMatch] as $model) {
        foreach (array_keys($model->getAttributes()) as $column) {
            expect($column)->not->toContain('year')
                ->and($column)->not->toContain('duration')
                ->and($column)->not->toContain('tenure');
        }
    }
});
