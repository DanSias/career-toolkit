<?php

use App\Enums\AgentRunStatus;
use App\Enums\WorkflowStatus;
use App\Models\AgentRun;
use App\Models\Application;
use App\Models\ApplicationQuestion;
use App\Models\WorkflowRun;
use App\Models\WorkflowStep;
use App\Support\ApplicationInspection\PresentApplicationInspection;

function succeededAgentRunWithQuestions(Application $application, int $questionCount): AgentRun
{
    $workflowRun = WorkflowRun::factory()->for($application)->create(['status' => WorkflowStatus::Succeeded]);
    $step = WorkflowStep::factory()->for($workflowRun, 'workflowRun')->create(['status' => WorkflowStatus::Succeeded]);
    $agentRun = AgentRun::factory()->for($step, 'workflowStep')->create([
        'status' => AgentRunStatus::Succeeded,
        'inspection_outcome' => 'complete',
        'ats_detected' => 'greenhouse',
    ]);

    for ($i = 0; $i < $questionCount; $i++) {
        ApplicationQuestion::factory()->for($application)->for($agentRun, 'agentRun')->create(['position' => $i]);
    }

    return $agentRun;
}

it('presents the first successful inspection when only one exists', function () {
    $application = Application::factory()->create();
    succeededAgentRunWithQuestions($application, 3);

    $presented = (new PresentApplicationInspection)->present($application);

    expect($presented['latest_result']['field_count'])->toBe(3);
});

it('presents the LATEST successful inspection when multiple exist, not simply all questions ever created', function () {
    $application = Application::factory()->create();
    succeededAgentRunWithQuestions($application, 3);
    $second = succeededAgentRunWithQuestions($application, 5);

    $presented = (new PresentApplicationInspection)->present($application);

    expect($presented['latest_result']['agent_run_id'])->toBe($second->id)
        ->and($presented['latest_result']['field_count'])->toBe(5);
});

it('keeps older inspection questions in the database even though the presenter only shows the latest', function () {
    $application = Application::factory()->create();
    succeededAgentRunWithQuestions($application, 3);
    succeededAgentRunWithQuestions($application, 5);

    expect(ApplicationQuestion::where('application_id', $application->id)->count())->toBe(8);
});

it('does not let a later failed inspection erase the previous successful result', function () {
    $application = Application::factory()->create();
    succeededAgentRunWithQuestions($application, 3);

    $failedRun = WorkflowRun::factory()->for($application)->create(['status' => WorkflowStatus::Failed, 'failure_message' => 'boom']);

    $presented = (new PresentApplicationInspection)->present($application);

    expect($presented['workflow_run']['status'])->toBe('failed')
        ->and($presented['workflow_run']['failure_message'])->toBe('boom')
        ->and($presented['latest_result']['field_count'])->toBe(3);
});

it('presents null latest_result when no inspection has ever succeeded', function () {
    $application = Application::factory()->create();
    WorkflowRun::factory()->for($application)->create(['status' => WorkflowStatus::Failed]);

    $presented = (new PresentApplicationInspection)->present($application);

    expect($presented['latest_result'])->toBeNull();
});

it('presents null workflow_run when no inspection has ever been dispatched', function () {
    $application = Application::factory()->create();

    $presented = (new PresentApplicationInspection)->present($application);

    expect($presented['workflow_run'])->toBeNull()
        ->and($presented['latest_result'])->toBeNull();
});
