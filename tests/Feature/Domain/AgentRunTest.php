<?php

use App\Enums\AgentRunFailureCategory;
use App\Enums\AgentRunStatus;
use App\Enums\InspectionOutcome;
use App\Models\AgentRun;
use App\Models\ApplicationQuestion;
use App\Models\WorkflowStep;
use Illuminate\Database\QueryException;

it('belongs to a workflow step', function () {
    $step = WorkflowStep::factory()->create();
    $agentRun = AgentRun::factory()->for($step, 'workflowStep')->create();

    expect($agentRun->workflowStep->is($step))->toBeTrue();
});

it('lets a workflow step own multiple agent runs', function () {
    $step = WorkflowStep::factory()->create();
    AgentRun::factory()->for($step, 'workflowStep')->count(2)->create();

    expect($step->agentRuns)->toHaveCount(2);
});

it('has many application questions', function () {
    $agentRun = AgentRun::factory()->create();
    ApplicationQuestion::factory()->for($agentRun, 'agentRun')->count(2)->create();

    expect($agentRun->applicationQuestions)->toHaveCount(2);
});

it('casts status, inspection_outcome, and failure_category to their enums', function () {
    $agentRun = AgentRun::factory()->create([
        'status' => AgentRunStatus::Succeeded,
        'inspection_outcome' => InspectionOutcome::AuthenticationRequired,
        'failure_category' => null,
    ]);

    expect($agentRun->fresh()->status)->toBe(AgentRunStatus::Succeeded)
        ->and($agentRun->fresh()->inspection_outcome)->toBe(InspectionOutcome::AuthenticationRequired);

    $failed = AgentRun::factory()->create([
        'status' => AgentRunStatus::Failed,
        'failure_category' => AgentRunFailureCategory::NavigationTimeout,
    ]);

    expect($failed->fresh()->failure_category)->toBe(AgentRunFailureCategory::NavigationTimeout);
});

it('leaves status and inspection_outcome as independent, orthogonal values', function () {
    $agentRun = AgentRun::factory()->create([
        'status' => AgentRunStatus::Succeeded,
        'inspection_outcome' => InspectionOutcome::Partial,
    ]);

    expect($agentRun->status)->toBe(AgentRunStatus::Succeeded)
        ->and($agentRun->inspection_outcome)->toBe(InspectionOutcome::Partial);
});

it('casts result_summary to an array', function () {
    $agentRun = AgentRun::factory()->create([
        'result_summary' => ['url_final' => 'https://example.com', 'load_ms' => 3747, 'warnings' => []],
    ]);

    expect($agentRun->fresh()->result_summary)->toBe([
        'url_final' => 'https://example.com',
        'load_ms' => 3747,
        'warnings' => [],
    ]);
});

it('scopes to queued and running attempts as active', function () {
    $step = WorkflowStep::factory()->create();
    $queued = AgentRun::factory()->for($step, 'workflowStep')->create(['status' => AgentRunStatus::Queued]);
    $running = AgentRun::factory()->for($step, 'workflowStep')->create(['status' => AgentRunStatus::Running]);
    AgentRun::factory()->for($step, 'workflowStep')->create(['status' => AgentRunStatus::Succeeded]);
    AgentRun::factory()->for($step, 'workflowStep')->create(['status' => AgentRunStatus::Failed]);

    $activeIds = AgentRun::query()->active()->pluck('id')->all();

    expect($activeIds)->toEqualCanonicalizing([$queued->id, $running->id]);
});

it('blocks deletion while an application question still references it', function () {
    $agentRun = AgentRun::factory()->create();
    ApplicationQuestion::factory()->for($agentRun, 'agentRun')->create();

    expect(fn () => $agentRun->delete())->toThrow(QueryException::class);
});
