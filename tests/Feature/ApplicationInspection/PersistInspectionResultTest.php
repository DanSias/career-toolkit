<?php

use App\Enums\AgentRunStatus;
use App\Enums\WorkflowStatus;
use App\Models\AgentRun;
use App\Models\Application;
use App\Models\ApplicationQuestion;
use App\Models\JobPosting;
use App\Models\WorkflowRun;
use App\Models\WorkflowStep;
use App\Support\ApplicationInspection\PersistInspectionResult;

function persistTestAgentRun(): AgentRun
{
    $jobPosting = JobPosting::factory()->create();
    $application = Application::factory()->for($jobPosting, 'jobPosting')->create();
    $workflowRun = WorkflowRun::factory()->for($application)->create(['status' => WorkflowStatus::Running]);
    $step = WorkflowStep::factory()->for($workflowRun, 'workflowRun')->create(['status' => WorkflowStatus::Running]);

    return AgentRun::factory()->for($step, 'workflowStep')->create(['status' => AgentRunStatus::Running]);
}

it('does not mark the workflow successful if question persistence fails mid-transaction', function () {
    $agentRun = persistTestAgentRun();

    // Bypasses HTTP/FormRequest validation entirely — calls the service
    // directly with a payload no controller could ever actually pass
    // through, specifically to prove the transaction itself (not just
    // upstream validation) protects against a partial write: the first
    // field is genuinely valid and would insert cleanly on its own; the
    // second has a label_source string with no matching enum case,
    // which throws when ApplicationQuestion casts it, after the first
    // insert already ran.
    $payload = [
        'status' => 'succeeded',
        'inspection_outcome' => 'complete',
        'ats' => 'greenhouse',
        'fields' => [
            [
                'position' => 0,
                'external_field_id' => 'first_name',
                'raw_label' => 'First Name',
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
                'raw_label' => 'Broken',
                'label_source' => 'not_a_real_enum_case',
                'control_type' => 'text',
                'required' => null,
                'options' => null,
                'section' => null,
                'extraction_source' => 'dom',
            ],
        ],
        'warnings' => [],
        'diagnostics' => [],
    ];

    expect(fn () => (new PersistInspectionResult)->persist($agentRun, $payload))
        ->toThrow(ValueError::class);

    expect($agentRun->fresh()->status)->toBe(AgentRunStatus::Running)
        ->and(ApplicationQuestion::where('agent_run_id', $agentRun->id)->count())->toBe(0)
        ->and($agentRun->workflowStep->fresh()->status)->toBe(WorkflowStatus::Running)
        ->and($agentRun->workflowStep->workflowRun->fresh()->status)->toBe(WorkflowStatus::Running);
});
