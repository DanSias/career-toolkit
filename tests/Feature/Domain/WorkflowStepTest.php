<?php

use App\Enums\WorkflowStatus;
use App\Models\AgentRun;
use App\Models\WorkflowRun;
use App\Models\WorkflowStep;

it('belongs to a workflow run', function () {
    $run = WorkflowRun::factory()->create();
    $step = WorkflowStep::factory()->for($run, 'workflowRun')->create();

    expect($step->workflowRun->is($run))->toBeTrue();
});

it('lets a workflow run own multiple steps', function () {
    $run = WorkflowRun::factory()->create();
    WorkflowStep::factory()->for($run, 'workflowRun')->count(2)->create();

    expect($run->workflowSteps)->toHaveCount(2);
});

it('has many agent runs', function () {
    $step = WorkflowStep::factory()->create();
    AgentRun::factory()->for($step, 'workflowStep')->count(2)->create();

    expect($step->agentRuns)->toHaveCount(2);
});

it('casts status to the WorkflowStatus enum', function () {
    $step = WorkflowStep::factory()->create(['status' => WorkflowStatus::Failed]);

    expect($step->fresh()->status)->toBe(WorkflowStatus::Failed);
});

it('deletes its agent runs when deleted', function () {
    $step = WorkflowStep::factory()->create();
    $agentRun = AgentRun::factory()->for($step, 'workflowStep')->create();

    $step->delete();

    expect(AgentRun::find($agentRun->id))->toBeNull();
});
