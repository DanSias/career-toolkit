<?php

use App\Models\AgentRun;
use App\Models\CareerProfile;

/**
 * Exercises the real routes/api.php registration end to end — not just
 * the middleware class in isolation — so "protects only intended
 * routes" and "not reachable through normal unauthenticated use" are
 * genuinely proven against this application's actual route table.
 */
it('rejects a claim request with no token when none is configured (fails closed)', function () {
    config(['services.browser_worker.token' => '']);

    $this->postJson('/api/worker/agent-runs/claim')->assertUnauthorized();
});

it('rejects a claim request with no Authorization header even when a token IS configured', function () {
    config(['services.browser_worker.token' => 'correct-token']);

    $this->postJson('/api/worker/agent-runs/claim')->assertUnauthorized();
});

it('rejects a claim request with an invalid token', function () {
    config(['services.browser_worker.token' => 'correct-token']);

    $this->withHeader('Authorization', 'Bearer wrong-token')
        ->postJson('/api/worker/agent-runs/claim')
        ->assertUnauthorized();
});

it('accepts a claim request with the correct token', function () {
    config(['services.browser_worker.token' => 'correct-token']);

    $this->withHeader('Authorization', 'Bearer correct-token')
        ->postJson('/api/worker/agent-runs/claim')
        ->assertNoContent();
});

it('guards the result endpoint with the same middleware', function () {
    config(['services.browser_worker.token' => 'correct-token']);
    $agentRun = AgentRun::factory()->create();

    $this->withHeader('Authorization', 'Bearer wrong-token')
        ->postJson("/api/worker/agent-runs/{$agentRun->id}/result", ['status' => 'failed'])
        ->assertUnauthorized();
});

it('never protects an ordinary web/Inertia route with the worker middleware', function () {
    // A worker token, valid or not, must be irrelevant to the normal
    // application surface — the two auth boundaries are structurally
    // separate (App\Http\Middleware\AuthenticateBrowserWorker is
    // applied only inside routes/api.php).
    config(['services.browser_worker.token' => 'correct-token']);
    CareerProfile::factory()->create();

    $this->withHeader('Authorization', 'Bearer wrong-token')
        ->get('/jobs')
        ->assertOk();
});
