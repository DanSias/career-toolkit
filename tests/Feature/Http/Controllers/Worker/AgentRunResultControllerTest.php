<?php

use App\Enums\AgentRunFailureCategory;
use App\Enums\AgentRunStatus;
use App\Enums\ApplicationQuestionExtractionSource;
use App\Enums\ApplicationQuestionLabelSource;
use App\Enums\WorkflowStatus;
use App\Models\AgentRun;
use App\Models\Application;
use App\Models\ApplicationQuestion;
use App\Models\JobPosting;
use App\Models\WorkflowRun;
use App\Models\WorkflowStep;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    config(['services.browser_worker.token' => 'test-token']);
});

function runningAgentRun(): AgentRun
{
    $jobPosting = JobPosting::factory()->create(['source_url' => 'https://example.com/jobs/1']);
    $application = Application::factory()->for($jobPosting, 'jobPosting')->create();
    $workflowRun = WorkflowRun::factory()->for($application)->create(['status' => WorkflowStatus::Running]);
    $step = WorkflowStep::factory()->for($workflowRun, 'workflowRun')->create(['status' => WorkflowStatus::Running]);

    return AgentRun::factory()->for($step, 'workflowStep')->create([
        'status' => AgentRunStatus::Running,
        'started_at' => now(),
        'claim_expires_at' => now()->addMinute(),
    ]);
}

function reportResult(AgentRun $agentRun, array $payload): TestResponse
{
    return test()->withHeader('Authorization', 'Bearer test-token')
        ->postJson("/api/worker/agent-runs/{$agentRun->id}/result", $payload);
}

function completeSuccessPayload(): array
{
    return [
        'status' => 'succeeded',
        'inspection_outcome' => 'complete',
        'ats' => 'greenhouse',
        'requested_url' => 'https://example.com/jobs/1',
        'final_url' => 'https://example.com/jobs/1',
        'fields' => [
            [
                'position' => 0,
                'external_field_id' => 'first_name',
                'raw_label' => 'First Name*',
                'label_source' => 'dom_label_for',
                'control_type' => 'text',
                'required' => true,
                'options' => null,
                'section' => null,
                'extraction_source' => 'dom',
            ],
            [
                'position' => 1,
                'external_field_id' => null,
                'raw_label' => null,
                'label_source' => 'unresolved',
                'control_type' => 'input',
                'required' => null,
                'options' => null,
                'section' => null,
                'extraction_source' => 'dom',
            ],
        ],
        'warnings' => ['a mild warning'],
        'diagnostics' => ['field_count' => 2, 'unresolved_label_count' => 1],
    ];
}

it('persists a complete successful result transactionally', function () {
    $agentRun = runningAgentRun();

    reportResult($agentRun, completeSuccessPayload())->assertOk()->assertJson(['accepted' => true, 'duplicate' => false]);

    $agentRun->refresh();
    expect($agentRun->status)->toBe(AgentRunStatus::Succeeded)
        ->and($agentRun->inspection_outcome->value)->toBe('complete')
        ->and($agentRun->ats_detected)->toBe('greenhouse')
        ->and($agentRun->finished_at)->not->toBeNull()
        ->and($agentRun->result_summary['warnings'])->toBe(['a mild warning'])
        ->and($agentRun->applicationQuestions)->toHaveCount(2);

    expect($agentRun->workflowStep->fresh()->status)->toBe(WorkflowStatus::Succeeded)
        ->and($agentRun->workflowStep->workflowRun->fresh()->status)->toBe(WorkflowStatus::Succeeded);
});

it('preserves position exactly, not insertion order', function () {
    $agentRun = runningAgentRun();
    $payload = completeSuccessPayload();
    // Reverse the array's own order to prove position (not array/insertion order) is what's trusted.
    $payload['fields'] = array_reverse($payload['fields']);

    reportResult($agentRun, $payload)->assertOk();

    $ordered = ApplicationQuestion::where('agent_run_id', $agentRun->id)->orderBy('position')->pluck('raw_label');
    expect($ordered->all())->toBe(['First Name*', null]);
});

it('preserves nullable uncertainty and options JSON exactly', function () {
    $agentRun = runningAgentRun();
    $payload = completeSuccessPayload();
    $payload['fields'][] = [
        'position' => 2,
        'external_field_id' => 'country',
        'raw_label' => 'Country',
        'label_source' => 'dom_aria_labelledby',
        'control_type' => 'select',
        'required' => false,
        'options' => ['United States', 'Canada'],
        'section' => 'Apply for this job',
        'extraction_source' => 'both',
    ];

    reportResult($agentRun, $payload)->assertOk();

    $unresolved = ApplicationQuestion::where('agent_run_id', $agentRun->id)->where('position', 1)->firstOrFail();
    $select = ApplicationQuestion::where('agent_run_id', $agentRun->id)->where('position', 2)->firstOrFail();

    expect($unresolved->raw_label)->toBeNull()
        ->and($unresolved->required)->toBeNull()
        ->and($unresolved->options)->toBeNull()
        ->and($unresolved->label_source)->toBe(ApplicationQuestionLabelSource::Unresolved)
        ->and($select->options)->toBe(['United States', 'Canada'])
        ->and($select->required)->toBeFalse()
        ->and($select->extraction_source)->toBe(ApplicationQuestionExtractionSource::Both);
});

it('rejects a malformed enum value', function () {
    $agentRun = runningAgentRun();
    $payload = completeSuccessPayload();
    $payload['fields'][0]['label_source'] = 'not_a_real_label_source';

    reportResult($agentRun, $payload)->assertUnprocessable();

    expect($agentRun->fresh()->status)->toBe(AgentRunStatus::Running);
});

it('rejects a worker attempting to report claim_timeout as its own failure_category', function () {
    $agentRun = runningAgentRun();

    reportResult($agentRun, [
        'status' => 'failed',
        'failure_category' => 'claim_timeout',
        'failure_message' => 'nice try',
    ])->assertUnprocessable();

    expect($agentRun->fresh()->status)->toBe(AgentRunStatus::Running);
});

it('persists an unsupported-ATS result as a successful technical execution', function () {
    $agentRun = runningAgentRun();

    reportResult($agentRun, [
        'status' => 'succeeded',
        'inspection_outcome' => 'unsupported',
        'ats' => null,
        'fields' => [],
        'warnings' => [],
        'diagnostics' => [],
    ])->assertOk();

    $agentRun->refresh();
    expect($agentRun->status)->toBe(AgentRunStatus::Succeeded)
        ->and($agentRun->inspection_outcome->value)->toBe('unsupported')
        ->and($agentRun->ats_detected)->toBeNull()
        ->and($agentRun->applicationQuestions)->toHaveCount(0);
});

it('marks all three execution levels failed on a technical failure and creates no questions', function () {
    $agentRun = runningAgentRun();

    reportResult($agentRun, [
        'status' => 'failed',
        'failure_category' => 'navigation_timeout',
        'failure_message' => 'page.goto timed out',
    ])->assertOk();

    $agentRun->refresh();
    expect($agentRun->status)->toBe(AgentRunStatus::Failed)
        ->and($agentRun->failure_category)->toBe(AgentRunFailureCategory::NavigationTimeout)
        ->and($agentRun->failure_message)->toBe('page.goto timed out')
        ->and($agentRun->applicationQuestions)->toHaveCount(0);

    expect($agentRun->workflowStep->fresh()->status)->toBe(WorkflowStatus::Failed)
        ->and($agentRun->workflowStep->workflowRun->fresh()->status)->toBe(WorkflowStatus::Failed);
});

// See tests/Feature/ApplicationInspection/PersistInspectionResultTest.php
// "does not mark the workflow successful if question persistence fails
// mid-transaction" for a genuine transactional-rollback proof at the
// service level — SQLite doesn't enforce column length, so a malformed
// HTTP payload large/invalid enough to fail at the DB layer is
// necessarily already rejected by ReportInspectionResultRequest first,
// which the "rejects a malformed enum value" test above already covers.

it('treats a duplicate identical result delivery as an idempotent no-op', function () {
    $agentRun = runningAgentRun();
    $payload = completeSuccessPayload();

    reportResult($agentRun, $payload)->assertOk()->assertJson(['accepted' => true, 'duplicate' => false]);
    $firstFinishedAt = $agentRun->fresh()->finished_at;
    $firstQuestionCount = ApplicationQuestion::where('agent_run_id', $agentRun->id)->count();

    reportResult($agentRun, $payload)->assertOk()->assertJson(['accepted' => true, 'duplicate' => true]);

    expect(ApplicationQuestion::where('agent_run_id', $agentRun->id)->count())->toBe($firstQuestionCount)
        ->and($agentRun->fresh()->finished_at->equalTo($firstFinishedAt))->toBeTrue();
});

it('rejects a stale result for an agent run a claim-timeout recovery already failed', function () {
    $agentRun = runningAgentRun();
    // Simulate the exact race the architecture names: the lease
    // expired and was recovered as failed before this (slow, since-
    // abandoned) worker's result finally arrived.
    $agentRun->update([
        'status' => AgentRunStatus::Failed,
        'failure_category' => AgentRunFailureCategory::ClaimTimeout,
        'finished_at' => now(),
    ]);
    $agentRun->workflowStep->update(['status' => WorkflowStatus::Failed]);
    $agentRun->workflowStep->workflowRun->update(['status' => WorkflowStatus::Failed]);

    reportResult($agentRun, completeSuccessPayload())
        ->assertStatus(409)
        ->assertJson(['accepted' => false, 'reason' => 'agent_run_not_running', 'current_status' => 'failed']);

    expect(ApplicationQuestion::where('agent_run_id', $agentRun->id)->count())->toBe(0);
});

it('cannot target another Application — application_id is derived server-side, never from the payload', function () {
    $agentRun = runningAgentRun();
    $otherApplication = Application::factory()->create();
    $payload = completeSuccessPayload();
    $payload['application_id'] = $otherApplication->id; // ignored — not a real field on this contract

    reportResult($agentRun, $payload)->assertOk();

    $question = ApplicationQuestion::where('agent_run_id', $agentRun->id)->first();
    expect($question->application_id)->toBe($agentRun->workflowStep->workflowRun->application_id)
        ->and($question->application_id)->not->toBe($otherApplication->id);
});
