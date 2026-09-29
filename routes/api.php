<?php

use App\Http\Controllers\Worker\AgentRunClaimController;
use App\Http\Controllers\Worker\AgentRunResultController;
use App\Http\Middleware\AuthenticateBrowserWorker;
use Illuminate\Support\Facades\Route;

// The browser worker's entire protocol surface — machine-only, never
// called from the frontend. See docs/application-inspector.md
// "Approved future worker transport" and
// App\Http\Middleware\AuthenticateBrowserWorker.
Route::middleware(AuthenticateBrowserWorker::class)->prefix('worker')->group(function () {
    Route::post('/agent-runs/claim', [AgentRunClaimController::class, 'store'])
        ->name('worker.agent-runs.claim');

    Route::post('/agent-runs/{agentRun}/result', [AgentRunResultController::class, 'store'])
        ->name('worker.agent-runs.result');
});
