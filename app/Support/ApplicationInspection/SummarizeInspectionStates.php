<?php

namespace App\Support\ApplicationInspection;

use App\Enums\AgentRunStatus;
use App\Enums\InspectionOutcome;
use App\Enums\WorkflowStatus;
use App\Enums\WorkflowType;
use App\Models\AgentRun;
use App\Models\Application;
use App\Models\ApplicationQuestion;
use App\Models\WorkflowRun;
use Illuminate\Support\Collection;

/**
 * Bulk-derives a compact inspection-state summary per JobPosting for
 * the Opportunities index/detail — never a second inspection-status
 * column on JobPosting, always computed from the same durable
 * WorkflowRun/AgentRun history App\Support\ApplicationInspection\
 * PresentApplicationInspection already reads for the Application
 * page. Bulk (2-3 queries total, not N+1) because this runs once per
 * Opportunities index render across every listed JobPosting. See
 * docs/application-inspector.md "Opportunities inspection state".
 */
final class SummarizeInspectionStates
{
    /**
     * @param  Collection<int, int>  $jobPostingIds
     * @return array<int, array<string, mixed>> keyed by job_posting_id
     */
    public function forJobPostings(Collection $jobPostingIds): array
    {
        $jobPostingIds = $jobPostingIds->values();

        $applications = Application::query()
            ->whereIn('job_posting_id', $jobPostingIds)
            ->get(['id', 'job_posting_id']);

        $applicationIdToJobPostingId = $applications->pluck('job_posting_id', 'id');
        $applicationIds = $applications->pluck('id');

        $summaries = [];
        foreach ($jobPostingIds as $jobPostingId) {
            $summaries[$jobPostingId] = $this->notInspected();
        }

        if ($applicationIds->isEmpty()) {
            return $summaries;
        }

        $latestRunByApplication = $this->latestWorkflowRunByApplication($applicationIds);
        $latestSuccessfulAgentRunByApplication = $this->latestSuccessfulAgentRunByApplication($applicationIds);
        $fieldCountByAgentRunId = $this->fieldCountByAgentRunId($latestSuccessfulAgentRunByApplication);

        foreach ($applications as $application) {
            $jobPostingId = $application->job_posting_id;
            $latestRun = $latestRunByApplication->get($application->id);
            $latestSuccessfulAgentRun = $latestSuccessfulAgentRunByApplication->get($application->id);
            $fieldCount = $latestSuccessfulAgentRun !== null
                ? ($fieldCountByAgentRunId->get($latestSuccessfulAgentRun->id) ?? 0)
                : null;

            $summaries[$jobPostingId] = $this->deriveState($application->id, $latestRun, $latestSuccessfulAgentRun, $fieldCount);
        }

        return $summaries;
    }

    /**
     * @return array<string, mixed>
     */
    public function forApplication(?Application $application): array
    {
        if ($application === null) {
            return $this->notInspected();
        }

        return $this->forJobPostings(collect([$application->job_posting_id]))[$application->job_posting_id];
    }

    /**
     * @return array<string, mixed>
     */
    private function deriveState(int $applicationId, ?WorkflowRun $latestRun, ?AgentRun $latestSuccessfulAgentRun, ?int $fieldCount): array
    {
        $base = [
            'application_id' => $applicationId,
            'field_count' => null,
            'latest_attempt_failed' => false,
        ];

        if ($latestRun === null) {
            return array_merge($base, ['state' => 'not_inspected']);
        }

        if ($latestRun->status === WorkflowStatus::Pending) {
            return array_merge($base, ['state' => 'queued']);
        }

        if ($latestRun->status === WorkflowStatus::Running) {
            return array_merge($base, ['state' => 'running']);
        }

        $hasSuccessfulResult = $latestSuccessfulAgentRun !== null
            && $latestSuccessfulAgentRun->inspection_outcome !== InspectionOutcome::Unsupported;

        if ($latestRun->status === WorkflowStatus::Succeeded) {
            if ($latestSuccessfulAgentRun?->inspection_outcome === InspectionOutcome::Unsupported) {
                return array_merge($base, ['state' => 'unsupported']);
            }

            return array_merge($base, ['state' => 'inspected', 'field_count' => $fieldCount]);
        }

        // Failed or Cancelled: a prior successful result, if one
        // exists, is never hidden just because the latest attempt
        // wasn't — see docs/application-inspector.md "Latest
        // successful inspection".
        if ($hasSuccessfulResult) {
            return array_merge($base, [
                'state' => 'inspected',
                'field_count' => $fieldCount,
                'latest_attempt_failed' => true,
            ]);
        }

        return array_merge($base, ['state' => 'failed']);
    }

    /**
     * @return array<string, mixed>
     */
    private function notInspected(): array
    {
        return [
            'state' => 'not_inspected',
            'application_id' => null,
            'field_count' => null,
            'latest_attempt_failed' => false,
        ];
    }

    /**
     * @param  Collection<int, int>  $applicationIds
     * @return Collection<int, WorkflowRun> keyed by application_id
     */
    private function latestWorkflowRunByApplication(Collection $applicationIds): Collection
    {
        return WorkflowRun::query()
            ->whereIn('application_id', $applicationIds)
            ->where('workflow_type', WorkflowType::ApplicationInspection)
            ->get()
            ->groupBy('application_id')
            ->map(fn (Collection $runs) => $runs->sortByDesc('id')->first());
    }

    /**
     * @param  Collection<int, int>  $applicationIds
     * @return Collection<int, AgentRun> keyed by application_id
     */
    private function latestSuccessfulAgentRunByApplication(Collection $applicationIds): Collection
    {
        return AgentRun::query()
            ->whereHas('workflowStep.workflowRun', function ($query) use ($applicationIds) {
                $query->whereIn('application_id', $applicationIds)
                    ->where('workflow_type', WorkflowType::ApplicationInspection);
            })
            ->where('status', AgentRunStatus::Succeeded)
            ->with('workflowStep.workflowRun:id,application_id')
            ->get()
            ->groupBy(fn (AgentRun $run) => $run->workflowStep->workflowRun->application_id)
            ->map(fn (Collection $runs) => $runs->sortByDesc('id')->first());
    }

    /**
     * @param  Collection<int, AgentRun>  $latestSuccessfulAgentRunByApplication
     * @return Collection<int, int> keyed by agent_run_id
     */
    private function fieldCountByAgentRunId(Collection $latestSuccessfulAgentRunByApplication): Collection
    {
        $agentRunIds = $latestSuccessfulAgentRunByApplication->pluck('id')->values();

        if ($agentRunIds->isEmpty()) {
            return collect();
        }

        return ApplicationQuestion::query()
            ->whereIn('agent_run_id', $agentRunIds)
            ->selectRaw('agent_run_id, count(*) as field_count')
            ->groupBy('agent_run_id')
            ->get()
            ->pluck('field_count', 'agent_run_id');
    }
}
