<?php

use App\Contracts\GeneratesJobMatch;
use App\Enums\GenerationStatus;
use App\Enums\GenerationType;
use App\Jobs\GenerateJobMatchJob;
use App\Models\GenerationAttempt;
use App\Models\JobAnalysis;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeJobMatchProvider;
use Tests\Support\JobMatchFixtures;

/**
 * JobMatchController::store() no longer calls the generation pipeline
 * synchronously — it only creates/looks up a GenerationAttempt and
 * dispatches GenerateJobMatchJob. See docs/job-match-generation.md
 * "Async Job Match". The job's own lifecycle behavior is covered
 * separately in GenerateJobMatchJobTest.php. Mirrors
 * tests/Feature/JobAnalysis/JobAnalysisControllerAsyncTest.php.
 */
it('creates a queued JobMatch GenerationAttempt on POST', function () {
    Queue::fake();
    ['analysis' => $analysis] = JobMatchFixtures::job();
    $job = $analysis->jobPosting;

    $this->post(route('jobs.analyses.matches.store', [$job, $analysis]));

    expect(GenerationAttempt::count())->toBe(1);

    $attempt = GenerationAttempt::first();
    expect($attempt->generation_type)->toBe(GenerationType::JobMatch)
        ->and($attempt->status)->toBe(GenerationStatus::Queued)
        ->and($attempt->subject_id)->toBe($analysis->id)
        ->and($attempt->subject_type)->toBe((new JobAnalysis)->getMorphClass())
        ->and($attempt->queued_at)->not->toBeNull();
});

it('dispatches GenerateJobMatchJob referencing the new attempt', function () {
    Queue::fake();
    ['analysis' => $analysis] = JobMatchFixtures::job();
    $job = $analysis->jobPosting;

    $this->post(route('jobs.analyses.matches.store', [$job, $analysis]));

    $attempt = GenerationAttempt::first();

    Queue::assertPushed(GenerateJobMatchJob::class, fn (GenerateJobMatchJob $dispatched) => $dispatched->attempt->is($attempt));
});

it('returns immediately without running GenerateJobMatch synchronously', function () {
    Queue::fake();
    ['analysis' => $analysis] = JobMatchFixtures::job();
    $job = $analysis->jobPosting;
    $fake = new FakeJobMatchProvider;
    app()->instance(GeneratesJobMatch::class, $fake);

    $response = $this->post(route('jobs.analyses.matches.store', [$job, $analysis]));

    $response->assertRedirect(route('jobs.analyses.show', [$job, $analysis]));
    expect($fake->callCount)->toBe(0);
});

it('does not create or dispatch another attempt while one is already queued for the same analysis', function () {
    Queue::fake();
    ['analysis' => $analysis] = JobMatchFixtures::job();
    $job = $analysis->jobPosting;

    $this->post(route('jobs.analyses.matches.store', [$job, $analysis]));
    $this->post(route('jobs.analyses.matches.store', [$job, $analysis]));

    expect(GenerationAttempt::count())->toBe(1);
    Queue::assertPushed(GenerateJobMatchJob::class, 1);
});

it('does not create or dispatch another attempt while one is already running for the same analysis', function () {
    Queue::fake();
    ['analysis' => $analysis] = JobMatchFixtures::job();
    $job = $analysis->jobPosting;
    GenerationAttempt::factory()->create([
        'subject_type' => (new JobAnalysis)->getMorphClass(),
        'subject_id' => $analysis->id,
        'generation_type' => GenerationType::JobMatch,
        'status' => GenerationStatus::Running,
    ]);

    $this->post(route('jobs.analyses.matches.store', [$job, $analysis]));

    expect(GenerationAttempt::count())->toBe(1);
    Queue::assertNothingPushed();
});

it('allows a new attempt once the prior attempt for the same analysis has failed', function () {
    Queue::fake();
    ['analysis' => $analysis] = JobMatchFixtures::job();
    $job = $analysis->jobPosting;
    GenerationAttempt::factory()->create([
        'subject_type' => (new JobAnalysis)->getMorphClass(),
        'subject_id' => $analysis->id,
        'generation_type' => GenerationType::JobMatch,
        'status' => GenerationStatus::Failed,
    ]);

    $this->post(route('jobs.analyses.matches.store', [$job, $analysis]));

    expect(GenerationAttempt::count())->toBe(2);
    Queue::assertPushed(GenerateJobMatchJob::class, 1);
});

it('allows a new attempt once the prior attempt for the same analysis has succeeded', function () {
    Queue::fake();
    ['analysis' => $analysis] = JobMatchFixtures::job();
    $job = $analysis->jobPosting;
    GenerationAttempt::factory()->create([
        'subject_type' => (new JobAnalysis)->getMorphClass(),
        'subject_id' => $analysis->id,
        'generation_type' => GenerationType::JobMatch,
        'status' => GenerationStatus::Succeeded,
    ]);

    $this->post(route('jobs.analyses.matches.store', [$job, $analysis]));

    expect(GenerationAttempt::count())->toBe(2);
    Queue::assertPushed(GenerateJobMatchJob::class, 1);
});

it('does not let an active attempt for a different analysis block this one', function () {
    Queue::fake();
    ['analysis' => $analysis] = JobMatchFixtures::job();
    $job = $analysis->jobPosting;
    $otherAnalysis = JobAnalysis::factory()->create();
    GenerationAttempt::factory()->create([
        'subject_type' => (new JobAnalysis)->getMorphClass(),
        'subject_id' => $otherAnalysis->id,
        'generation_type' => GenerationType::JobMatch,
        'status' => GenerationStatus::Running,
    ]);

    $this->post(route('jobs.analyses.matches.store', [$job, $analysis]));

    expect(GenerationAttempt::where('subject_id', $analysis->id)->count())->toBe(1);
    Queue::assertPushed(GenerateJobMatchJob::class, 1);
});
