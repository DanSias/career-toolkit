<?php

namespace App\Support\ApplicationInspection;

use App\Enums\AgentRunStatus;
use App\Enums\ApplicationStatus;
use App\Enums\WorkflowStatus;
use App\Enums\WorkflowType;
use App\Models\Application;
use App\Models\JobPosting;
use App\Models\WorkflowRun;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Orchestrates "Inspect Application" for one JobPosting: reuse or
 * create its (at most one — see the applications.job_posting_id
 * unique constraint) Draft Application, reuse an already-active
 * inspection WorkflowRun if one exists, otherwise create a fresh
 * WorkflowRun -> WorkflowStep -> queued AgentRun. See
 * docs/application-inspector.md.
 *
 * Deliberately mirrors JobAnalysisController::store()'s existing
 * check-active -> create pattern one layer up (Application-scoped
 * rather than GenerationAttempt-scoped) — the same convention, not a
 * new one.
 */
final class DispatchApplicationInspection
{
    public function dispatch(JobPosting $jobPosting): WorkflowRun
    {
        if (blank($jobPosting->source_url)) {
            throw new InvalidArgumentException('This JobPosting has no application URL to inspect.');
        }

        return DB::transaction(function () use ($jobPosting) {
            $application = $this->findOrCreateDraftApplication($jobPosting);

            $active = $application->workflowRuns()
                ->active()
                ->where('workflow_type', WorkflowType::ApplicationInspection)
                ->latest('id')
                ->first();

            if ($active !== null) {
                return $active;
            }

            $workflowRun = $application->workflowRuns()->create([
                'workflow_type' => WorkflowType::ApplicationInspection,
                'status' => WorkflowStatus::Pending,
            ]);

            $step = $workflowRun->workflowSteps()->create([
                'step_key' => 'inspect_application',
                'status' => WorkflowStatus::Pending,
            ]);

            $step->agentRuns()->create([
                'agent_type' => 'browser_inspector',
                'status' => AgentRunStatus::Queued,
            ]);

            return $workflowRun;
        });
    }

    /**
     * A plain find-then-create, made race-safe by the
     * applications.job_posting_id unique constraint: if two requests
     * race past the initial find, the loser's create() throws a
     * unique-constraint violation, which we catch and simply
     * re-resolve to the winner's row — both requests converge on the
     * same Application, never two.
     */
    private function findOrCreateDraftApplication(JobPosting $jobPosting): Application
    {
        $existing = Application::where('job_posting_id', $jobPosting->id)->first();

        if ($existing !== null) {
            return $existing;
        }

        try {
            return Application::create([
                'job_posting_id' => $jobPosting->id,
                'status' => ApplicationStatus::Draft,
            ]);
        } catch (UniqueConstraintViolationException) {
            return Application::where('job_posting_id', $jobPosting->id)->firstOrFail();
        }
    }
}
