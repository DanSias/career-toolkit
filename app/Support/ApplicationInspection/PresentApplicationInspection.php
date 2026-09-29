<?php

namespace App\Support\ApplicationInspection;

use App\Enums\AgentRunStatus;
use App\Enums\ApplicationQuestionLabelSource;
use App\Enums\WorkflowStatus;
use App\Enums\WorkflowType;
use App\Models\AgentRun;
use App\Models\Application;
use App\Models\ApplicationQuestion;
use App\Models\WorkflowRun;

/**
 * The one safe, minimal JSON shape a browser is ever handed for an
 * Application's inspection state — used identically by the polling
 * endpoint and by ApplicationController::show()'s initial page props,
 * mirroring App\Support\GenerationAttempt\PresentGenerationAttempt's
 * own established convention exactly.
 *
 * Deliberately shows the latest inspection WorkflowRun's own status
 * (for polling: pending/running keeps polling; any terminal status
 * stops) separately from the latest SUCCESSFUL AgentRun's extracted
 * questions — a later failed run never erases a prior successful
 * extraction. See docs/application-inspector.md "Latest successful
 * inspection".
 */
final class PresentApplicationInspection
{
    /**
     * @return array<string, mixed>
     */
    public function present(Application $application): array
    {
        $latestRun = $application->workflowRuns()
            ->where('workflow_type', WorkflowType::ApplicationInspection)
            ->latest('id')
            ->first();

        $latestSuccessfulAgentRun = $this->latestSuccessfulAgentRun($application);

        return [
            'application_id' => $application->id,
            'workflow_run' => $latestRun !== null ? $this->presentWorkflowRun($latestRun) : null,
            'latest_result' => $latestSuccessfulAgentRun !== null ? $this->presentResult($latestSuccessfulAgentRun) : null,
        ];
    }

    private function latestSuccessfulAgentRun(Application $application): ?AgentRun
    {
        return AgentRun::query()
            ->whereHas('workflowStep.workflowRun', function ($query) use ($application) {
                $query->where('application_id', $application->id)
                    ->where('workflow_type', WorkflowType::ApplicationInspection);
            })
            ->where('status', AgentRunStatus::Succeeded)
            ->latest('id')
            ->first();
    }

    /**
     * @return array<string, mixed>
     */
    private function presentWorkflowRun(WorkflowRun $run): array
    {
        return [
            'id' => $run->id,
            'status' => $run->status->value,
            'failure_message' => $run->status === WorkflowStatus::Failed ? $run->failure_message : null,
            'started_at' => $run->started_at?->toIso8601String(),
            'finished_at' => $run->finished_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentResult(AgentRun $agentRun): array
    {
        $questions = $agentRun->applicationQuestions()->orderBy('position')->get();

        return [
            'agent_run_id' => $agentRun->id,
            'ats_detected' => $agentRun->ats_detected,
            'inspection_outcome' => $agentRun->inspection_outcome?->value,
            'finished_at' => $agentRun->finished_at?->toIso8601String(),
            'warnings' => $agentRun->result_summary['warnings'] ?? [],
            'field_count' => $questions->count(),
            'unresolved_count' => $questions->where('label_source', ApplicationQuestionLabelSource::Unresolved)->count(),
            'questions' => $questions->map(fn (ApplicationQuestion $q) => [
                'position' => $q->position,
                'raw_label' => $q->raw_label,
                'label_source' => $q->label_source->value,
                'control_type' => $q->control_type,
                'required' => $q->required,
                'options' => $q->options,
                'section' => $q->section,
                'extraction_source' => $q->extraction_source->value,
            ])->values()->all(),
        ];
    }
}
