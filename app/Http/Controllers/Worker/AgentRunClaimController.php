<?php

namespace App\Http\Controllers\Worker;

use App\Http\Controllers\Controller;
use App\Support\ApplicationInspection\ClaimNextAgentRun;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The worker's poll/claim endpoint — machine-only, guarded by
 * App\Http\Middleware\AuthenticateBrowserWorker (routes/api.php),
 * never reachable from the frontend. Returns the minimum payload a
 * browser_inspector worker needs to run one inspection — never
 * Career Profile data, resume content, or any Application field beyond
 * the one URL it exists to inspect. See docs/application-inspector.md
 * "Claim protocol".
 */
class AgentRunClaimController extends Controller
{
    public function store(Request $request, ClaimNextAgentRun $claimer): JsonResponse|Response
    {
        $workerIdentity = (string) $request->string('worker_identity', 'unknown');

        $agentRun = $claimer->claim($workerIdentity);

        if ($agentRun === null) {
            return response()->noContent();
        }

        $jobPosting = $agentRun->workflowStep->workflowRun->application->jobPosting;

        return response()->json([
            'agent_run_id' => $agentRun->id,
            'application_url' => $jobPosting->source_url,
            'inspection_policy' => [
                'ats' => 'greenhouse',
            ],
        ]);
    }
}
