<?php

use App\Enums\WorkflowStatus;
use App\Enums\WorkflowType;
use App\Models\Application;
use App\Models\WorkflowRun;
use App\Models\WorkflowStep;

it('belongs to an application', function () {
    $application = Application::factory()->create();
    $run = WorkflowRun::factory()->for($application)->create();

    expect($run->application->is($application))->toBeTrue();
});

it('lets an application own multiple workflow runs', function () {
    $application = Application::factory()->create();
    WorkflowRun::factory()->for($application)->count(2)->create();

    expect($application->workflowRuns)->toHaveCount(2);
});

it('has many workflow steps', function () {
    $run = WorkflowRun::factory()->create();
    WorkflowStep::factory()->for($run, 'workflowRun')->count(2)->create();

    expect($run->workflowSteps)->toHaveCount(2);
});

it('casts workflow_type and status to their enums', function () {
    $run = WorkflowRun::factory()->create([
        'workflow_type' => WorkflowType::ApplicationInspection,
        'status' => WorkflowStatus::Running,
    ]);
    $run = $run->fresh();

    expect($run->workflow_type)->toBe(WorkflowType::ApplicationInspection)
        ->and($run->status)->toBe(WorkflowStatus::Running);
});

it('scopes to pending and running runs as active', function () {
    $application = Application::factory()->create();
    $pending = WorkflowRun::factory()->for($application)->create(['status' => WorkflowStatus::Pending]);
    $running = WorkflowRun::factory()->for($application)->create(['status' => WorkflowStatus::Running]);
    WorkflowRun::factory()->for($application)->create(['status' => WorkflowStatus::Succeeded]);
    WorkflowRun::factory()->for($application)->create(['status' => WorkflowStatus::Failed]);
    WorkflowRun::factory()->for($application)->create(['status' => WorkflowStatus::Cancelled]);

    $activeIds = WorkflowRun::query()->active()->pluck('id')->all();

    expect($activeIds)->toEqualCanonicalizing([$pending->id, $running->id]);
});

it('deletes its workflow steps when deleted', function () {
    $run = WorkflowRun::factory()->create();
    $step = WorkflowStep::factory()->for($run, 'workflowRun')->create();

    $run->delete();

    expect(WorkflowStep::find($step->id))->toBeNull();
});
