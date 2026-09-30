<?php

use App\Models\WorkerHeartbeat;

it('returns offline with no identity when no worker has ever been seen', function () {
    $this->getJson(route('browser-worker.status'))
        ->assertOk()
        ->assertJson(['online' => false, 'identity' => null, 'last_seen_at' => null]);
});

it('returns the current worker presence when one exists', function () {
    WorkerHeartbeat::factory()->create(['identity' => 'ai-box-browser-inspector', 'last_seen_at' => now()]);

    $this->getJson(route('browser-worker.status'))
        ->assertOk()
        ->assertJson(['online' => true, 'identity' => 'ai-box-browser-inspector', 'worker_type' => 'browser_inspector']);
});

it('is reachable without worker authentication — it is a normal session-authenticated read, not the worker protocol', function () {
    $this->getJson(route('browser-worker.status'))->assertOk();
});
