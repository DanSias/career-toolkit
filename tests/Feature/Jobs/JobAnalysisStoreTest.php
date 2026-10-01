<?php

use App\Jobs\GenerateJobAnalysisJob;
use App\Models\GenerationAttempt;
use App\Models\JobPosting;
use Illuminate\Support\Facades\Queue;

/**
 * JobAnalysisController::store() no longer generates a JobAnalysis
 * synchronously — it only creates/looks up a GenerationAttempt and
 * dispatches GenerateJobAnalysisJob. That dispatch/duplicate-prevention
 * behavior is covered by
 * tests/Feature/JobAnalysis/JobAnalysisControllerAsyncTest.php, and the
 * job's own generate/validate/persist lifecycle by
 * tests/Feature/JobAnalysis/GenerateJobAnalysisJobTest.php. See
 * docs/job-analysis-generation.md "Async Job Analysis". Only the
 * route-model-binding behavior below is still this route's own concern.
 */
it('returns 404 when generating for a nonexistent job posting', function () {
    $this->post('/jobs/999999/analyses')->assertNotFound();
});

it('blocks direct analysis POSTs for incomplete descriptions without explicit consent', function (string $completeness, mixed $override) {
    Queue::fake();
    $job = JobPosting::factory()->create(['description_completeness' => $completeness]);
    $payload = $override === null ? [] : ['allow_incomplete_description' => $override];
    $this->postJson(route('jobs.analyses.store', $job), $payload)
        ->assertUnprocessable()->assertJsonValidationErrors('allow_incomplete_description');
    expect(GenerationAttempt::count())->toBe(0);
    Queue::assertNothingPushed();
})->with([
    ['preview', null], ['preview', false], ['preview', 'false'], ['preview', 'yes'], ['preview', []],
    ['unknown', null], ['unknown', false], ['unknown', 'false'], ['unknown', 'yes'], ['unknown', []],
]);

it('accepts an explicit incomplete-description override', function (string $completeness) {
    Queue::fake();
    $job = JobPosting::factory()->create(['description_completeness' => $completeness]);
    $this->post(route('jobs.analyses.store', $job), ['allow_incomplete_description' => true])
        ->assertRedirect(route('jobs.show', $job));
    expect(GenerationAttempt::count())->toBe(1);
    Queue::assertPushed(GenerateJobAnalysisJob::class, 1);
})->with(['preview', 'unknown']);
