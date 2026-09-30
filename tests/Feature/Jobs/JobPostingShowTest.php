<?php

use App\Enums\GenerationStatus;
use App\Enums\GenerationType;
use App\Models\CareerProfile;
use App\Models\GenerationAttempt;
use App\Models\JobAnalysis;
use App\Models\JobPosting;
use Inertia\Testing\AssertableInertia as Assert;

it('renders the job detail page with the original description', function () {
    $profile = CareerProfile::factory()->create();
    $job = JobPosting::factory()->for($profile)->create([
        'company' => 'Acme Corp',
        'title' => 'Senior Engineer',
        'location' => 'Remote',
        'source_url' => 'https://example.com/jobs/123',
        'description' => "Responsibilities:\n- Build things\n- Ship things",
    ]);

    $this->get(route('jobs.show', $job))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('jobs/show')
            ->where('job.company', 'Acme Corp')
            ->where('job.title', 'Senior Engineer')
            ->where('job.location', 'Remote')
            ->where('job.source_url', 'https://example.com/jobs/123')
            ->where('job.description', "Responsibilities:\n- Build things\n- Ship things")
        );
});

it('omits location and source URL from the payload when absent, rather than sending empty strings', function () {
    $profile = CareerProfile::factory()->create();
    $job = JobPosting::factory()->for($profile)->create(['location' => null, 'source_url' => null]);

    $this->get(route('jobs.show', $job))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('job.location', null)
            ->where('job.source_url', null)
        );
});

it('returns a 404 for an unknown job id', function () {
    $this->get('/jobs/999999')->assertNotFound();
});

it('exposes no latest_job_analysis_attempt when none has ever been made', function () {
    $job = JobPosting::factory()->create();

    $this->get(route('jobs.show', $job))
        ->assertInertia(fn (Assert $page) => $page->where('job.latest_job_analysis_attempt', null));
});

it('exposes a queued attempt as the latest_job_analysis_attempt, so a reload shows generation in progress', function () {
    $job = JobPosting::factory()->create();
    $attempt = GenerationAttempt::factory()->create([
        'subject_type' => (new JobPosting)->getMorphClass(),
        'subject_id' => $job->id,
        'generation_type' => GenerationType::JobAnalysis,
        'status' => GenerationStatus::Queued,
    ]);

    $this->get(route('jobs.show', $job))
        ->assertInertia(fn (Assert $page) => $page
            ->where('job.latest_job_analysis_attempt.id', $attempt->id)
            ->where('job.latest_job_analysis_attempt.status', 'queued')
        );
});

it('exposes a running attempt as the latest_job_analysis_attempt', function () {
    $job = JobPosting::factory()->create();
    GenerationAttempt::factory()->create([
        'subject_type' => (new JobPosting)->getMorphClass(),
        'subject_id' => $job->id,
        'generation_type' => GenerationType::JobAnalysis,
        'status' => GenerationStatus::Running,
    ]);

    $this->get(route('jobs.show', $job))
        ->assertInertia(fn (Assert $page) => $page->where('job.latest_job_analysis_attempt.status', 'running'));
});

it('exposes the latest failed attempt so a reload still explains why it failed', function () {
    $job = JobPosting::factory()->create();
    GenerationAttempt::factory()->create([
        'subject_type' => (new JobPosting)->getMorphClass(),
        'subject_id' => $job->id,
        'generation_type' => GenerationType::JobAnalysis,
        'status' => GenerationStatus::Failed,
        'failure_message' => 'Ollama request failed: connection error.',
    ]);

    $this->get(route('jobs.show', $job))
        ->assertInertia(fn (Assert $page) => $page
            ->where('job.latest_job_analysis_attempt.status', 'failed')
            ->where('job.latest_job_analysis_attempt.failure_message', 'Ollama request failed: connection error.')
        );
});

it('does not expose a succeeded attempt as latest_job_analysis_attempt, since its result already appears in analyses', function () {
    $job = JobPosting::factory()->create();
    $analysis = JobAnalysis::factory()->create(['job_posting_id' => $job->id]);
    GenerationAttempt::factory()->create([
        'subject_type' => (new JobPosting)->getMorphClass(),
        'subject_id' => $job->id,
        'generation_type' => GenerationType::JobAnalysis,
        'status' => GenerationStatus::Succeeded,
        'result_id' => $analysis->id,
    ]);

    $this->get(route('jobs.show', $job))
        ->assertInertia(fn (Assert $page) => $page->where('job.latest_job_analysis_attempt', null));
});

it('exposes a not_inspected inspection summary when no Application exists yet', function () {
    $job = JobPosting::factory()->create();

    $this->get(route('jobs.show', $job))
        ->assertInertia(fn (Assert $page) => $page
            ->where('job.inspection.state', 'not_inspected')
            ->where('job.application_id', null)
        );
});

it('exposes only the most recent attempt when several exist for the same posting', function () {
    $job = JobPosting::factory()->create();
    GenerationAttempt::factory()->create([
        'subject_type' => (new JobPosting)->getMorphClass(),
        'subject_id' => $job->id,
        'generation_type' => GenerationType::JobAnalysis,
        'status' => GenerationStatus::Failed,
    ]);
    $latest = GenerationAttempt::factory()->create([
        'subject_type' => (new JobPosting)->getMorphClass(),
        'subject_id' => $job->id,
        'generation_type' => GenerationType::JobAnalysis,
        'status' => GenerationStatus::Running,
    ]);

    $this->get(route('jobs.show', $job))
        ->assertInertia(fn (Assert $page) => $page->where('job.latest_job_analysis_attempt.id', $latest->id));
});
