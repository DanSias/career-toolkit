<?php

use App\Enums\AgentRunFailureCategory;
use App\Enums\AgentRunStatus;
use App\Enums\WorkflowStatus;
use App\Models\AgentRun;
use App\Models\Application;
use App\Models\JobPosting;
use App\Models\WorkerHeartbeat;
use App\Models\WorkflowRun;
use App\Models\WorkflowStep;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    config(['services.browser_worker.token' => 'test-token']);
});

/**
 * Builds a full Application -> WorkflowRun -> WorkflowStep -> AgentRun
 * chain against a real JobPosting, returning the AgentRun (its
 * ancestors are reachable via relations for assertions).
 */
function queuedAgentRun(array $agentRunOverrides = [], array $workflowOverrides = []): AgentRun
{
    $jobPosting = JobPosting::factory()->create(['source_url' => 'https://example.com/jobs/1']);
    $application = Application::factory()->for($jobPosting, 'jobPosting')->create();
    $workflowRun = WorkflowRun::factory()->for($application)->create(array_merge(
        ['status' => WorkflowStatus::Pending],
        $workflowOverrides,
    ));
    $step = WorkflowStep::factory()->for($workflowRun, 'workflowRun')->create(array_merge(
        ['status' => WorkflowStatus::Pending],
        $workflowOverrides,
    ));

    return AgentRun::factory()->for($step, 'workflowStep')->create(array_merge(
        ['status' => AgentRunStatus::Queued],
        $agentRunOverrides,
    ));
}

function claimRequest(): TestResponse
{
    return test()->withHeader('Authorization', 'Bearer test-token')
        ->postJson('/api/worker/agent-runs/claim', ['worker_identity' => 'test-worker']);
}

it('returns no content when there is no queued work', function () {
    claimRequest()->assertNoContent();
});

it('atomically claims a queued browser_inspector agent run', function () {
    $agentRun = queuedAgentRun();
    $jobPosting = $agentRun->workflowStep->workflowRun->application->jobPosting;

    $response = claimRequest();

    $response->assertOk()->assertJson([
        'agent_run_id' => $agentRun->id,
        'application_url' => $jobPosting->source_url,
        'inspection_policy' => ['ats' => 'greenhouse'],
    ]);

    $agentRun->refresh();
    expect($agentRun->status)->toBe(AgentRunStatus::Running)
        ->and($agentRun->worker_identity)->toBe('test-worker')
        ->and($agentRun->started_at)->not->toBeNull()
        ->and($agentRun->claim_expires_at)->not->toBeNull();
});

it('never returns Career Profile data, resume content, or unrelated Application fields', function () {
    queuedAgentRun();

    $response = claimRequest();

    expect(array_keys($response->json()))->toEqualCanonicalizing([
        'agent_run_id', 'application_url', 'inspection_policy',
    ]);
});

it('marks the owning workflow step and workflow run running on claim', function () {
    $agentRun = queuedAgentRun();
    $step = $agentRun->workflowStep;
    $workflowRun = $step->workflowRun;

    claimRequest()->assertOk();

    expect($step->fresh()->status)->toBe(WorkflowStatus::Running)
        ->and($workflowRun->fresh()->status)->toBe(WorkflowStatus::Running);
});

it('never claims the same agent run twice', function () {
    queuedAgentRun();

    $first = claimRequest();
    $second = claimRequest();

    $first->assertOk();
    $second->assertNoContent();
});

it('never claims a non-browser_inspector agent run', function () {
    queuedAgentRun(['agent_type' => 'some_other_agent_type']);

    claimRequest()->assertNoContent();
});

it('records worker presence on an authenticated claim poll even when there is no work', function () {
    claimRequest()->assertNoContent();

    $heartbeat = WorkerHeartbeat::firstWhere('identity', 'test-worker');
    expect($heartbeat)->not->toBeNull()
        ->and($heartbeat->worker_type)->toBe('browser_inspector')
        ->and($heartbeat->last_seen_at)->not->toBeNull();
});

it('does not record presence for an unauthenticated claim attempt', function () {
    test()->postJson('/api/worker/agent-runs/claim', ['worker_identity' => 'sneaky-worker'])
        ->assertUnauthorized();

    expect(WorkerHeartbeat::count())->toBe(0);
});

it('upserts the same identity rather than accumulating a new row per poll', function () {
    claimRequest();
    claimRequest();
    claimRequest();

    expect(WorkerHeartbeat::where('identity', 'test-worker')->count())->toBe(1);
});

it('recovers an expired claim as failed with claim_timeout before assigning new work', function () {
    $stale = queuedAgentRun(
        agentRunOverrides: ['status' => AgentRunStatus::Running, 'claim_expires_at' => now()->subMinute()],
        workflowOverrides: ['status' => WorkflowStatus::Running],
    );
    $step = $stale->workflowStep;
    $workflowRun = $step->workflowRun;

    claimRequest()->assertNoContent();

    $stale->refresh();
    expect($stale->status)->toBe(AgentRunStatus::Failed)
        ->and($stale->failure_category)->toBe(AgentRunFailureCategory::ClaimTimeout)
        ->and($step->fresh()->status)->toBe(WorkflowStatus::Failed)
        ->and($workflowRun->fresh()->status)->toBe(WorkflowStatus::Failed);
});
