<?php

use App\Enums\GenerationStatus;
use App\Enums\GenerationType;
use App\Models\GenerationAttempt;
use App\Models\JobAnalysis;
use App\Models\JobMatch;
use App\Models\JobPosting;

/**
 * The one read-only polling surface the frontend uses to watch a
 * GenerationAttempt's durable status. See
 * docs/job-analysis-generation.md "Async Job Analysis".
 */
it('returns the expected safe JSON shape for a queued attempt', function () {
    $jobPosting = JobPosting::factory()->create();
    $attempt = GenerationAttempt::factory()->create([
        'subject_type' => (new JobPosting)->getMorphClass(),
        'subject_id' => $jobPosting->id,
        'generation_type' => GenerationType::JobAnalysis,
        'status' => GenerationStatus::Queued,
        'queued_at' => now(),
    ]);

    $this->getJson(route('generation-attempts.show', $attempt))
        ->assertOk()
        ->assertExactJson([
            'id' => $attempt->id,
            'status' => 'queued',
            'generation_type' => 'job_analysis',
            'queued_at' => $attempt->fresh()->queued_at->toIso8601String(),
            'started_at' => null,
            'finished_at' => null,
            'failure_message' => null,
            'result_url' => null,
        ]);
});

it('includes the correct existing JobAnalysis result URL once succeeded', function () {
    $jobPosting = JobPosting::factory()->create();
    $analysis = JobAnalysis::factory()->create(['job_posting_id' => $jobPosting->id]);
    $attempt = GenerationAttempt::factory()->create([
        'subject_type' => (new JobPosting)->getMorphClass(),
        'subject_id' => $jobPosting->id,
        'generation_type' => GenerationType::JobAnalysis,
        'status' => GenerationStatus::Succeeded,
        'result_id' => $analysis->id,
    ]);

    $response = $this->getJson(route('generation-attempts.show', $attempt))->assertOk();

    $response->assertJson([
        'status' => 'succeeded',
        'result_url' => route('jobs.analyses.show', [$jobPosting, $analysis]),
    ]);
});

it('includes the correct existing JobMatch result URL once succeeded, derived from the JobAnalysis subject', function () {
    $jobPosting = JobPosting::factory()->create();
    $analysis = JobAnalysis::factory()->create(['job_posting_id' => $jobPosting->id]);
    $match = JobMatch::factory()->create(['job_analysis_id' => $analysis->id]);
    $attempt = GenerationAttempt::factory()->create([
        'subject_type' => (new JobAnalysis)->getMorphClass(),
        'subject_id' => $analysis->id,
        'generation_type' => GenerationType::JobMatch,
        'status' => GenerationStatus::Succeeded,
        'result_id' => $match->id,
    ]);

    $response = $this->getJson(route('generation-attempts.show', $attempt))->assertOk();

    $response->assertJson([
        'status' => 'succeeded',
        'result_url' => route('jobs.analyses.matches.show', [$jobPosting, $analysis, $match]),
    ]);
});

it('exposes the failure message only once the attempt has actually failed', function () {
    $jobPosting = JobPosting::factory()->create();
    $attempt = GenerationAttempt::factory()->create([
        'subject_type' => (new JobPosting)->getMorphClass(),
        'subject_id' => $jobPosting->id,
        'generation_type' => GenerationType::JobAnalysis,
        'status' => GenerationStatus::Failed,
        'failure_category' => 'provider_error',
        'failure_message' => 'Ollama request failed: connection error.',
    ]);

    $this->getJson(route('generation-attempts.show', $attempt))
        ->assertOk()
        ->assertJson([
            'status' => 'failed',
            'failure_message' => 'Ollama request failed: connection error.',
            'result_url' => null,
        ]);
});

it('never exposes result_url for a non-succeeded attempt even if result_id is somehow set', function () {
    $jobPosting = JobPosting::factory()->create();
    $attempt = GenerationAttempt::factory()->create([
        'subject_type' => (new JobPosting)->getMorphClass(),
        'subject_id' => $jobPosting->id,
        'generation_type' => GenerationType::JobAnalysis,
        'status' => GenerationStatus::Running,
    ]);

    $this->getJson(route('generation-attempts.show', $attempt))
        ->assertOk()
        ->assertJson(['status' => 'running', 'result_url' => null, 'failure_message' => null]);
});

it('returns a 404 for an unknown attempt id', function () {
    $this->getJson('/generation-attempts/999999')->assertNotFound();
});
