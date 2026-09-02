<?php

use App\Contracts\GeneratesJobMatch;
use App\Models\JobMatch;
use App\Models\JobPosting;
use App\Support\JobMatch\JobMatchProviderResponse;
use Tests\Support\FakeJobMatchProvider;
use Tests\Support\JobMatchFixtures;

function bindFakeJobMatchProvider(): FakeJobMatchProvider
{
    $fake = new FakeJobMatchProvider;
    app()->instance(GeneratesJobMatch::class, $fake);

    return $fake;
}

it('generates a new immutable snapshot and redirects to its detail page', function () {
    ['factOne' => $factOne, 'education' => $education] = JobMatchFixtures::candidate();
    ['analysis' => $analysis, 'findingOne' => $findingOne, 'findingTwo' => $findingTwo] = JobMatchFixtures::job();
    $job = $analysis->jobPosting;

    bindFakeJobMatchProvider()->willReturn(new JobMatchProviderResponse(
        provider: 'openai',
        model: 'gpt-5.6-test',
        structuredContent: JobMatchFixtures::validResponse($findingOne, $findingTwo, $factOne, $education),
    ));

    $response = $this->post(route('jobs.analyses.matches.store', [$job, $analysis]));

    $match = JobMatch::sole();
    $response->assertRedirect(route('jobs.analyses.matches.show', [$job, $analysis, $match]));
});

it('allows generating a new match even when one already exists for the same pair', function () {
    ['factOne' => $factOne, 'education' => $education] = JobMatchFixtures::candidate();
    ['analysis' => $analysis, 'findingOne' => $findingOne, 'findingTwo' => $findingTwo] = JobMatchFixtures::job();
    $job = $analysis->jobPosting;

    bindFakeJobMatchProvider()->willReturn(new JobMatchProviderResponse(
        provider: 'openai',
        model: 'gpt-5.6-test',
        structuredContent: JobMatchFixtures::validResponse($findingOne, $findingTwo, $factOne, $education),
    ));

    $this->post(route('jobs.analyses.matches.store', [$job, $analysis]));
    $this->post(route('jobs.analyses.matches.store', [$job, $analysis]));

    expect(JobMatch::where('job_analysis_id', $analysis->id)->count())->toBe(2);
});

it('redirects back with a safe error message and persists nothing on generation failure', function () {
    ['factOne' => $factOne, 'education' => $education] = JobMatchFixtures::candidate();
    ['analysis' => $analysis, 'findingOne' => $findingOne, 'findingTwo' => $findingTwo] = JobMatchFixtures::job();
    $job = $analysis->jobPosting;

    // A genuinely invalid provider response, so the real validator is
    // what throws.
    $invalidResponse = JobMatchFixtures::validResponse($findingOne, $findingTwo, $factOne, $education);
    unset($invalidResponse['findings'][1]);
    $invalidResponse['findings'] = array_values($invalidResponse['findings']);
    bindFakeJobMatchProvider()->willReturn(new JobMatchProviderResponse('openai', 'gpt-5.6-test', $invalidResponse));

    $analysisShowUrl = route('jobs.analyses.show', [$job, $analysis]);
    $response = $this->from($analysisShowUrl)->post(route('jobs.analyses.matches.store', [$job, $analysis]));

    $response->assertRedirect($analysisShowUrl);
    $response->assertSessionHasErrors('match_generation');

    $message = session('errors')->get('match_generation')[0];
    expect($message)->not->toContain('job_analysis_finding_id')
        ->and($message)->not->toContain('Illuminate\\')
        ->and($message)->not->toContain('App\\Exceptions');
    expect(JobMatch::count())->toBe(0);
});

it('returns 404 when the JobAnalysis does not belong to the given JobPosting', function () {
    ['analysis' => $analysis] = JobMatchFixtures::job();
    $unrelatedJob = JobPosting::factory()->create();

    $this->post(route('jobs.analyses.matches.store', [$unrelatedJob, $analysis]))
        ->assertNotFound();
});

it('returns 404 for a nonexistent JobAnalysis', function () {
    $job = JobPosting::factory()->create();

    $this->post("/jobs/{$job->id}/analyses/999999/matches")->assertNotFound();
});
