<?php

use App\Enums\AgentRunStatus;
use App\Enums\WorkflowStatus;
use App\Models\AgentRun;
use App\Models\Application;
use App\Models\ApplicationQuestion;
use App\Models\JobPosting;
use App\Models\WorkflowRun;
use App\Models\WorkflowStep;
use App\Support\ApplicationInspection\SummarizeInspectionStates;

function summarize(JobPosting ...$jobPostings): array
{
    $ids = collect($jobPostings)->pluck('id');

    return (new SummarizeInspectionStates)->forJobPostings($ids);
}

function successfulAgentRunFor(Application $application, int $questionCount = 3, string $outcome = 'complete'): AgentRun
{
    $workflowRun = WorkflowRun::factory()->for($application)->create(['status' => WorkflowStatus::Succeeded]);
    $step = WorkflowStep::factory()->for($workflowRun, 'workflowRun')->create(['status' => WorkflowStatus::Succeeded]);
    $agentRun = AgentRun::factory()->for($step, 'workflowStep')->create([
        'status' => AgentRunStatus::Succeeded,
        'inspection_outcome' => $outcome,
    ]);

    for ($i = 0; $i < $questionCount; $i++) {
        ApplicationQuestion::factory()->for($application)->for($agentRun, 'agentRun')->create(['position' => $i]);
    }

    return $agentRun;
}

it('reports not_inspected for a JobPosting with no Application at all', function () {
    $job = JobPosting::factory()->create();

    expect(summarize($job)[$job->id]['state'])->toBe('not_inspected')
        ->and(summarize($job)[$job->id]['application_id'])->toBeNull();
});

it('reports queued when the latest WorkflowRun is pending', function () {
    $job = JobPosting::factory()->create();
    $application = Application::factory()->for($job, 'jobPosting')->create();
    WorkflowRun::factory()->for($application)->create(['status' => WorkflowStatus::Pending]);

    expect(summarize($job)[$job->id]['state'])->toBe('queued');
});

it('reports running when the latest WorkflowRun is running', function () {
    $job = JobPosting::factory()->create();
    $application = Application::factory()->for($job, 'jobPosting')->create();
    WorkflowRun::factory()->for($application)->create(['status' => WorkflowStatus::Running]);

    expect(summarize($job)[$job->id]['state'])->toBe('running');
});

it('reports inspected with a field count on a successful inspection', function () {
    $job = JobPosting::factory()->create();
    $application = Application::factory()->for($job, 'jobPosting')->create();
    successfulAgentRunFor($application, 7);

    $summary = summarize($job)[$job->id];
    expect($summary['state'])->toBe('inspected')
        ->and($summary['field_count'])->toBe(7)
        ->and($summary['latest_attempt_failed'])->toBeFalse();
});

it('reports unsupported when the successful inspection outcome is unsupported', function () {
    $job = JobPosting::factory()->create();
    $application = Application::factory()->for($job, 'jobPosting')->create();
    successfulAgentRunFor($application, 0, 'unsupported');

    expect(summarize($job)[$job->id]['state'])->toBe('unsupported');
});

it('reports failed when the only attempt failed', function () {
    $job = JobPosting::factory()->create();
    $application = Application::factory()->for($job, 'jobPosting')->create();
    WorkflowRun::factory()->for($application)->create(['status' => WorkflowStatus::Failed]);

    expect(summarize($job)[$job->id]['state'])->toBe('failed');
});

it('preserves a previous successful result distinctly when the latest attempt failed', function () {
    $job = JobPosting::factory()->create();
    $application = Application::factory()->for($job, 'jobPosting')->create();
    successfulAgentRunFor($application, 12);
    WorkflowRun::factory()->for($application)->create(['status' => WorkflowStatus::Failed]);

    $summary = summarize($job)[$job->id];
    expect($summary['state'])->toBe('inspected')
        ->and($summary['field_count'])->toBe(12)
        ->and($summary['latest_attempt_failed'])->toBeTrue();
});

it('summarizes multiple JobPostings independently in one bulk call', function () {
    $inspected = JobPosting::factory()->create();
    $inspectedApp = Application::factory()->for($inspected, 'jobPosting')->create();
    successfulAgentRunFor($inspectedApp, 4);

    $queued = JobPosting::factory()->create();
    $queuedApp = Application::factory()->for($queued, 'jobPosting')->create();
    WorkflowRun::factory()->for($queuedApp)->create(['status' => WorkflowStatus::Pending]);

    $untouched = JobPosting::factory()->create();

    $summaries = summarize($inspected, $queued, $untouched);

    expect($summaries[$inspected->id]['state'])->toBe('inspected')
        ->and($summaries[$queued->id]['state'])->toBe('queued')
        ->and($summaries[$untouched->id]['state'])->toBe('not_inspected');
});
