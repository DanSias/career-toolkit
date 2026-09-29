<?php

use App\Enums\ApplicationStatus;
use App\Models\Application;
use App\Models\ApplicationQuestion;
use App\Models\JobPosting;
use App\Models\WorkflowRun;
use Illuminate\Database\QueryException;

it('belongs to a job posting', function () {
    $job = JobPosting::factory()->create();
    $application = Application::factory()->for($job, 'jobPosting')->create();

    expect($application->jobPosting->is($job))->toBeTrue();
});

it('lets a job posting own multiple applications', function () {
    $job = JobPosting::factory()->create();
    Application::factory()->for($job, 'jobPosting')->count(3)->create();

    expect($job->applications)->toHaveCount(3);
});

it('has many workflow runs', function () {
    $application = Application::factory()->create();
    WorkflowRun::factory()->for($application)->count(2)->create();

    expect($application->workflowRuns)->toHaveCount(2);
});

it('has many application questions', function () {
    $application = Application::factory()->create();
    ApplicationQuestion::factory()->for($application)->count(2)->create();

    expect($application->applicationQuestions)->toHaveCount(2);
});

it('casts status to the ApplicationStatus enum', function () {
    $application = Application::factory()->create(['status' => ApplicationStatus::Draft]);

    expect($application->fresh()->status)->toBe(ApplicationStatus::Draft);
});

it('cascades its whole workflow tree and its application questions when deleted', function () {
    $application = Application::factory()->create();
    $workflowRun = WorkflowRun::factory()->for($application)->create();
    $question = ApplicationQuestion::factory()->for($application)->create();

    $application->delete();

    expect(WorkflowRun::find($workflowRun->id))->toBeNull()
        ->and(ApplicationQuestion::find($question->id))->toBeNull();
});

it('cannot be created against a nonexistent job posting', function () {
    expect(fn () => Application::factory()->create(['job_posting_id' => 0]))
        ->toThrow(QueryException::class);
});

it('blocks deletion of its job posting while it exists', function () {
    $job = JobPosting::factory()->create();
    Application::factory()->for($job, 'jobPosting')->create();

    expect(fn () => $job->delete())->toThrow(QueryException::class);
});
