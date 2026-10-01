<?php

use App\Contracts\GeneratesJobAnalysis;
use App\Enums\GenerationStatus;
use App\Enums\GenerationType;
use App\Jobs\GenerateJobAnalysisJob;
use App\Models\GenerationAttempt;
use App\Models\JobPosting;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeJobAnalysisProvider;

/**
 * JobAnalysisController::store() no longer calls the generation
 * pipeline synchronously — it only creates/looks up a
 * GenerationAttempt and dispatches GenerateJobAnalysisJob. See
 * docs/job-analysis-generation.md "Async Job Analysis". The job's own
 * lifecycle behavior is covered separately in
 * GenerateJobAnalysisJobTest.php.
 */
it('creates a queued GenerationAttempt on POST', function () {
    Queue::fake();
    $jobPosting = JobPosting::factory()->create(['description_completeness' => 'complete']);

    $this->post(route('jobs.analyses.store', $jobPosting));

    expect(GenerationAttempt::count())->toBe(1);

    $attempt = GenerationAttempt::first();
    expect($attempt->generation_type)->toBe(GenerationType::JobAnalysis)
        ->and($attempt->status)->toBe(GenerationStatus::Queued)
        ->and($attempt->subject_id)->toBe($jobPosting->id)
        ->and($attempt->subject_type)->toBe((new JobPosting)->getMorphClass())
        ->and($attempt->queued_at)->not->toBeNull();
});

it('dispatches GenerateJobAnalysisJob referencing the new attempt', function () {
    Queue::fake();
    $jobPosting = JobPosting::factory()->create(['description_completeness' => 'complete']);

    $this->post(route('jobs.analyses.store', $jobPosting));

    $attempt = GenerationAttempt::first();

    Queue::assertPushed(GenerateJobAnalysisJob::class, fn (GenerateJobAnalysisJob $job) => $job->attempt->is($attempt));
});

it('returns immediately without running GenerateJobAnalysis synchronously', function () {
    Queue::fake();
    $jobPosting = JobPosting::factory()->create(['description_completeness' => 'complete']);
    $fake = new FakeJobAnalysisProvider;
    app()->instance(GeneratesJobAnalysis::class, $fake);

    $response = $this->post(route('jobs.analyses.store', $jobPosting));

    $response->assertRedirect(route('jobs.show', $jobPosting));
    expect($fake->callCount)->toBe(0);
});

it('redirects back to the JobPosting page, not a JobAnalysis show page', function () {
    Queue::fake();
    $jobPosting = JobPosting::factory()->create(['description_completeness' => 'complete']);

    $response = $this->post(route('jobs.analyses.store', $jobPosting));

    $response->assertRedirect(route('jobs.show', $jobPosting));
});

it('does not create or dispatch another attempt while one is already queued for the same posting', function () {
    Queue::fake();
    $jobPosting = JobPosting::factory()->create(['description_completeness' => 'complete']);

    $this->post(route('jobs.analyses.store', $jobPosting));
    $this->post(route('jobs.analyses.store', $jobPosting));

    expect(GenerationAttempt::count())->toBe(1);
    Queue::assertPushed(GenerateJobAnalysisJob::class, 1);
});

it('does not create or dispatch another attempt while one is already running for the same posting', function () {
    Queue::fake();
    $jobPosting = JobPosting::factory()->create(['description_completeness' => 'complete']);
    GenerationAttempt::factory()->create([
        'subject_type' => (new JobPosting)->getMorphClass(),
        'subject_id' => $jobPosting->id,
        'generation_type' => GenerationType::JobAnalysis,
        'status' => GenerationStatus::Running,
    ]);

    $this->post(route('jobs.analyses.store', $jobPosting));

    expect(GenerationAttempt::count())->toBe(1);
    Queue::assertNothingPushed();
});

it('allows a new attempt once the prior attempt for the same posting has failed', function () {
    Queue::fake();
    $jobPosting = JobPosting::factory()->create(['description_completeness' => 'complete']);
    GenerationAttempt::factory()->create([
        'subject_type' => (new JobPosting)->getMorphClass(),
        'subject_id' => $jobPosting->id,
        'generation_type' => GenerationType::JobAnalysis,
        'status' => GenerationStatus::Failed,
    ]);

    $this->post(route('jobs.analyses.store', $jobPosting));

    expect(GenerationAttempt::count())->toBe(2);
    Queue::assertPushed(GenerateJobAnalysisJob::class, 1);
});

it('allows a new attempt once the prior attempt for the same posting has succeeded', function () {
    Queue::fake();
    $jobPosting = JobPosting::factory()->create(['description_completeness' => 'complete']);
    GenerationAttempt::factory()->create([
        'subject_type' => (new JobPosting)->getMorphClass(),
        'subject_id' => $jobPosting->id,
        'generation_type' => GenerationType::JobAnalysis,
        'status' => GenerationStatus::Succeeded,
    ]);

    $this->post(route('jobs.analyses.store', $jobPosting));

    expect(GenerationAttempt::count())->toBe(2);
    Queue::assertPushed(GenerateJobAnalysisJob::class, 1);
});

it('does not let an active attempt for a different posting block this posting', function () {
    Queue::fake();
    $jobPosting = JobPosting::factory()->create(['description_completeness' => 'complete']);
    $otherJobPosting = JobPosting::factory()->create(['description_completeness' => 'complete']);
    GenerationAttempt::factory()->create([
        'subject_type' => (new JobPosting)->getMorphClass(),
        'subject_id' => $otherJobPosting->id,
        'generation_type' => GenerationType::JobAnalysis,
        'status' => GenerationStatus::Running,
    ]);

    $this->post(route('jobs.analyses.store', $jobPosting));

    expect(GenerationAttempt::where('subject_id', $jobPosting->id)->count())->toBe(1);
    Queue::assertPushed(GenerateJobAnalysisJob::class, 1);
});
