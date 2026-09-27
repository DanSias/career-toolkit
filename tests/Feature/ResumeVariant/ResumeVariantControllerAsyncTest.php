<?php

use App\Contracts\GeneratesResumeSelection;
use App\Enums\GenerationStatus;
use App\Enums\GenerationType;
use App\Jobs\GenerateResumeVariantJob;
use App\Models\GenerationAttempt;
use App\Models\JobMatch;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeResumeSelectionProvider;
use Tests\Support\ResumeVariantFixtures;

/**
 * ResumeVariantController::store() no longer calls the generation
 * pipeline synchronously — it only creates/looks up a GenerationAttempt
 * and dispatches GenerateResumeVariantJob. See
 * docs/resume-variant-generation.md "Async Resume". The job's own
 * lifecycle behavior is covered separately in
 * GenerateResumeVariantJobTest.php. Mirrors
 * tests/Feature/JobMatch/JobMatchControllerAsyncTest.php.
 */
function resumeControllerAsyncMatch(): JobMatch
{
    $candidate = ResumeVariantFixtures::candidate();
    $job = ResumeVariantFixtures::job();

    return ResumeVariantFixtures::jobMatch(
        $candidate['profile'], $job['analysis'], $job['azureFinding'], $job['ownershipFinding'],
        $candidate['factAws'], $candidate['factIndependent'],
    );
}

it('creates a queued ResumeVariant GenerationAttempt on POST', function () {
    Queue::fake();
    $match = resumeControllerAsyncMatch();
    $jobPosting = $match->jobAnalysis->jobPosting;

    $this->post(route('jobs.analyses.matches.resume.store', [$jobPosting, $match->jobAnalysis, $match]));

    expect(GenerationAttempt::count())->toBe(1);

    $attempt = GenerationAttempt::first();
    expect($attempt->generation_type)->toBe(GenerationType::ResumeVariant)
        ->and($attempt->status)->toBe(GenerationStatus::Queued)
        ->and($attempt->subject_id)->toBe($match->id)
        ->and($attempt->subject_type)->toBe((new JobMatch)->getMorphClass())
        ->and($attempt->queued_at)->not->toBeNull();
});

it('dispatches GenerateResumeVariantJob referencing the new attempt', function () {
    Queue::fake();
    $match = resumeControllerAsyncMatch();
    $jobPosting = $match->jobAnalysis->jobPosting;

    $this->post(route('jobs.analyses.matches.resume.store', [$jobPosting, $match->jobAnalysis, $match]));

    $attempt = GenerationAttempt::first();

    Queue::assertPushed(GenerateResumeVariantJob::class, fn (GenerateResumeVariantJob $dispatched) => $dispatched->attempt->is($attempt));
});

it('returns immediately without running GenerateResumeVariant synchronously', function () {
    Queue::fake();
    $match = resumeControllerAsyncMatch();
    $jobPosting = $match->jobAnalysis->jobPosting;
    $fake = new FakeResumeSelectionProvider;
    app()->instance(GeneratesResumeSelection::class, $fake);

    $response = $this->post(route('jobs.analyses.matches.resume.store', [$jobPosting, $match->jobAnalysis, $match]));

    $response->assertRedirect(route('jobs.analyses.matches.show', [$jobPosting, $match->jobAnalysis, $match]));
    expect($fake->callCount)->toBe(0);
});

it('does not create or dispatch another attempt while one is already queued for the same match', function () {
    Queue::fake();
    $match = resumeControllerAsyncMatch();
    $jobPosting = $match->jobAnalysis->jobPosting;

    $this->post(route('jobs.analyses.matches.resume.store', [$jobPosting, $match->jobAnalysis, $match]));
    $this->post(route('jobs.analyses.matches.resume.store', [$jobPosting, $match->jobAnalysis, $match]));

    expect(GenerationAttempt::count())->toBe(1);
    Queue::assertPushed(GenerateResumeVariantJob::class, 1);
});

it('does not create or dispatch another attempt while one is already running for the same match', function () {
    Queue::fake();
    $match = resumeControllerAsyncMatch();
    $jobPosting = $match->jobAnalysis->jobPosting;
    GenerationAttempt::factory()->create([
        'subject_type' => (new JobMatch)->getMorphClass(),
        'subject_id' => $match->id,
        'generation_type' => GenerationType::ResumeVariant,
        'status' => GenerationStatus::Running,
    ]);

    $this->post(route('jobs.analyses.matches.resume.store', [$jobPosting, $match->jobAnalysis, $match]));

    expect(GenerationAttempt::count())->toBe(1);
    Queue::assertNothingPushed();
});

it('allows a new attempt once the prior attempt for the same match has failed', function () {
    Queue::fake();
    $match = resumeControllerAsyncMatch();
    $jobPosting = $match->jobAnalysis->jobPosting;
    GenerationAttempt::factory()->create([
        'subject_type' => (new JobMatch)->getMorphClass(),
        'subject_id' => $match->id,
        'generation_type' => GenerationType::ResumeVariant,
        'status' => GenerationStatus::Failed,
    ]);

    $this->post(route('jobs.analyses.matches.resume.store', [$jobPosting, $match->jobAnalysis, $match]));

    expect(GenerationAttempt::count())->toBe(2);
    Queue::assertPushed(GenerateResumeVariantJob::class, 1);
});

it('allows a new attempt once the prior attempt for the same match has succeeded, matching existing product behavior of allowing multiple resumes per match', function () {
    Queue::fake();
    $match = resumeControllerAsyncMatch();
    $jobPosting = $match->jobAnalysis->jobPosting;
    GenerationAttempt::factory()->create([
        'subject_type' => (new JobMatch)->getMorphClass(),
        'subject_id' => $match->id,
        'generation_type' => GenerationType::ResumeVariant,
        'status' => GenerationStatus::Succeeded,
    ]);

    $this->post(route('jobs.analyses.matches.resume.store', [$jobPosting, $match->jobAnalysis, $match]));

    expect(GenerationAttempt::count())->toBe(2);
    Queue::assertPushed(GenerateResumeVariantJob::class, 1);
});

it('does not let an active attempt for a different match block this one', function () {
    Queue::fake();
    $match = resumeControllerAsyncMatch();
    $jobPosting = $match->jobAnalysis->jobPosting;
    $otherMatch = JobMatch::factory()->create();
    GenerationAttempt::factory()->create([
        'subject_type' => (new JobMatch)->getMorphClass(),
        'subject_id' => $otherMatch->id,
        'generation_type' => GenerationType::ResumeVariant,
        'status' => GenerationStatus::Running,
    ]);

    $this->post(route('jobs.analyses.matches.resume.store', [$jobPosting, $match->jobAnalysis, $match]));

    expect(GenerationAttempt::where('subject_id', $match->id)->count())->toBe(1);
    Queue::assertPushed(GenerateResumeVariantJob::class, 1);
});
