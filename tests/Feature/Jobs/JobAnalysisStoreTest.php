<?php

use App\Contracts\GeneratesJobAnalysis;
use App\Models\JobAnalysis;
use App\Models\JobPosting;
use App\Support\JobAnalysis\JobAnalysisProviderResponse;
use Tests\Support\FakeJobAnalysisProvider;
use Tests\Support\JobAnalysisFixtures;

function bindFakeProvider(): FakeJobAnalysisProvider
{
    $fake = new FakeJobAnalysisProvider;
    app()->instance(GeneratesJobAnalysis::class, $fake);

    return $fake;
}

it('generates a new immutable snapshot and redirects to its detail page', function () {
    $job = JobPosting::factory()->create(['description' => JobAnalysisFixtures::DESCRIPTION]);
    bindFakeProvider()->willReturn(new JobAnalysisProviderResponse(
        provider: 'openai',
        model: 'gpt-5.6-test',
        structuredContent: JobAnalysisFixtures::validPayload(),
    ));

    $response = $this->post(route('jobs.analyses.store', $job));

    $analysis = JobAnalysis::sole();
    $response->assertRedirect(route('jobs.analyses.show', [$job, $analysis]));
});

it('allows generating a new analysis even when one already exists for the posting', function () {
    $job = JobPosting::factory()->create(['description' => JobAnalysisFixtures::DESCRIPTION]);
    bindFakeProvider()->willReturn(new JobAnalysisProviderResponse(
        provider: 'openai',
        model: 'gpt-5.6-test',
        structuredContent: JobAnalysisFixtures::validPayload(),
    ));

    $this->post(route('jobs.analyses.store', $job));
    $this->post(route('jobs.analyses.store', $job));

    expect(JobAnalysis::where('job_posting_id', $job->id)->count())->toBe(2);
});

it('redirects back with a safe error message and persists nothing on generation failure', function () {
    // A genuinely invalid provider response, so the real validator is
    // what throws — a fake standing in for the PROVIDER should never
    // throw InvalidJobAnalysisResponseException itself; only the
    // orchestrator's validator/evidence-verifier collaborators do.
    $job = JobPosting::factory()->create();
    $invalidPayload = JobAnalysisFixtures::validPayload();
    unset($invalidPayload['role_summary']);
    bindFakeProvider()->willReturn(new JobAnalysisProviderResponse('openai', 'gpt-5.6-test', $invalidPayload));

    $response = $this->from(route('jobs.show', $job))->post(route('jobs.analyses.store', $job));

    $response->assertRedirect(route('jobs.show', $job));
    $response->assertSessionHasErrors('generation');

    // The controller must never leak the underlying validator's raw
    // exception message (which does name the missing field) — only a
    // fixed, generic, safe message reaches the session/browser.
    $message = session('errors')->get('generation')[0];
    expect($message)->not->toContain('role_summary')
        ->and($message)->not->toContain('Illuminate\\')
        ->and($message)->not->toContain('App\\Exceptions');
    expect(JobAnalysis::count())->toBe(0);
});

it('returns 404 when generating for a nonexistent job posting', function () {
    $this->post('/jobs/999999/analyses')->assertNotFound();
});
