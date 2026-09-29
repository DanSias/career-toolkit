<?php

namespace App\Http\Controllers\Worker;

use App\Http\Controllers\Controller;
use App\Http\Requests\Worker\ReportInspectionResultRequest;
use App\Models\AgentRun;
use App\Support\ApplicationInspection\PersistInspectionResult;
use Illuminate\Http\JsonResponse;

/**
 * The worker's result-report endpoint — machine-only, guarded by
 * App\Http\Middleware\AuthenticateBrowserWorker (routes/api.php).
 * {agentRun} is the sole way a result is targeted — the request body
 * never carries application_id/workflow_run_id/workflow_step_id, so a
 * worker cannot address any Application/workflow other than the one it
 * was actually assigned. See docs/application-inspector.md "Result
 * protocol".
 */
class AgentRunResultController extends Controller
{
    public function store(ReportInspectionResultRequest $request, AgentRun $agentRun, PersistInspectionResult $persister): JsonResponse
    {
        $result = $persister->persist($agentRun, $request->validated());

        if ($result['outcome'] === 'stale') {
            return response()->json([
                'accepted' => false,
                'reason' => 'agent_run_not_running',
                'current_status' => $result['current_status'],
            ], 409);
        }

        return response()->json([
            'accepted' => true,
            'duplicate' => $result['outcome'] === 'duplicate',
        ]);
    }
}
