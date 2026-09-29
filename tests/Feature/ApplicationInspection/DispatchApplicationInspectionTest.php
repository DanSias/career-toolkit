<?php

use App\Enums\AgentRunStatus;
use App\Enums\ApplicationStatus;
use App\Enums\WorkflowStatus;
use App\Enums\WorkflowType;
use App\Models\Application;
use App\Models\JobPosting;
use App\Models\WorkflowRun;
use App\Support\ApplicationInspection\DispatchApplicationInspection;

function dispatcher(): DispatchApplicationInspection
{
    return new DispatchApplicationInspection;
}

it('creates a Draft Application, WorkflowRun, WorkflowStep, and queued AgentRun for a job posting with an application URL', function () {
    $jobPosting = JobPosting::factory()->create(['source_url' => 'https://job-boards.greenhouse.io/acme/jobs/1']);

    $workflowRun = dispatcher()->dispatch($jobPosting);

    expect(Application::count())->toBe(1);
    $application = Application::first();
    expect($application->job_posting_id)->toBe($jobPosting->id)
        ->and($application->status)->toBe(ApplicationStatus::Draft);

    expect($workflowRun->workflow_type)->toBe(WorkflowType::ApplicationInspection)
        ->and($workflowRun->status)->toBe(WorkflowStatus::Pending)
        ->and($workflowRun->workflowSteps)->toHaveCount(1);

    $step = $workflowRun->workflowSteps->first();
    expect($step->step_key)->toBe('inspect_application')
        ->and($step->status)->toBe(WorkflowStatus::Pending)
        ->and($step->agentRuns)->toHaveCount(1);

    $agentRun = $step->agentRuns->first();
    expect($agentRun->agent_type)->toBe('browser_inspector')
        ->and($agentRun->status)->toBe(AgentRunStatus::Queued);
});

it('rejects a job posting with no application URL cleanly, without creating any state', function () {
    $jobPosting = JobPosting::factory()->create(['source_url' => null]);

    expect(fn () => dispatcher()->dispatch($jobPosting))->toThrow(InvalidArgumentException::class);

    expect(Application::count())->toBe(0);
});

it('reuses the existing Draft Application on a second dispatch rather than creating another', function () {
    $jobPosting = JobPosting::factory()->create(['source_url' => 'https://example.com']);

    $first = dispatcher()->dispatch($jobPosting);
    // Terminal, so the second dispatch is a genuinely new, separate inspection.
    $first->update(['status' => WorkflowStatus::Succeeded]);

    dispatcher()->dispatch($jobPosting);

    expect(Application::count())->toBe(1);
});

it('does not create a duplicate active WorkflowRun on a double dispatch — the second call reuses the active one', function () {
    $jobPosting = JobPosting::factory()->create(['source_url' => 'https://example.com']);

    $first = dispatcher()->dispatch($jobPosting);
    $second = dispatcher()->dispatch($jobPosting);

    expect($second->id)->toBe($first->id)
        ->and(WorkflowRun::count())->toBe(1);
});

it('allows a new inspection once the previous WorkflowRun is terminal (succeeded)', function () {
    $jobPosting = JobPosting::factory()->create(['source_url' => 'https://example.com']);
    $first = dispatcher()->dispatch($jobPosting);
    $first->update(['status' => WorkflowStatus::Succeeded]);

    $second = dispatcher()->dispatch($jobPosting);

    expect($second->id)->not->toBe($first->id)
        ->and(WorkflowRun::count())->toBe(2);
});

it('retry after a failed WorkflowRun creates a brand new WorkflowRun/WorkflowStep/AgentRun, never reopening the old one', function () {
    $jobPosting = JobPosting::factory()->create(['source_url' => 'https://example.com']);
    $first = dispatcher()->dispatch($jobPosting);
    $first->update(['status' => WorkflowStatus::Failed, 'failure_message' => 'boom']);

    $retry = dispatcher()->dispatch($jobPosting);

    expect($retry->id)->not->toBe($first->id)
        ->and($retry->status)->toBe(WorkflowStatus::Pending)
        ->and($first->fresh()->status)->toBe(WorkflowStatus::Failed)
        ->and($first->fresh()->failure_message)->toBe('boom')
        ->and(WorkflowRun::count())->toBe(2);
});

it('allows a new inspection once the previous WorkflowRun was cancelled', function () {
    $jobPosting = JobPosting::factory()->create(['source_url' => 'https://example.com']);
    $first = dispatcher()->dispatch($jobPosting);
    $first->update(['status' => WorkflowStatus::Cancelled]);

    $second = dispatcher()->dispatch($jobPosting);

    expect($second->id)->not->toBe($first->id);
});
