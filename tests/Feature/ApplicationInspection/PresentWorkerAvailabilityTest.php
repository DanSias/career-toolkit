<?php

use App\Models\WorkerHeartbeat;
use App\Support\ApplicationInspection\PresentWorkerAvailability;

beforeEach(function () {
    config(['services.browser_worker.poll_interval_seconds' => 5]);
});

it('reports offline with a null last_seen_at when no worker has ever been seen', function () {
    $presented = (new PresentWorkerAvailability)->present();

    expect($presented)->toBe([
        'identity' => null,
        'worker_type' => null,
        'online' => false,
        'last_seen_at' => null,
    ]);
});

it('reports online when the last heartbeat is within the poll-interval-derived threshold', function () {
    WorkerHeartbeat::factory()->create(['last_seen_at' => now()->subSeconds(3)]);

    $presented = (new PresentWorkerAvailability)->present();

    expect($presented['online'])->toBeTrue();
});

it('reports offline once the last heartbeat exceeds the poll-interval-derived threshold', function () {
    WorkerHeartbeat::factory()->create(['last_seen_at' => now()->subSeconds(16)]);

    $presented = (new PresentWorkerAvailability)->present();

    expect($presented['online'])->toBeFalse();
});

it('derives the online threshold from the configured poll interval rather than a fixed constant', function () {
    config(['services.browser_worker.poll_interval_seconds' => 30]);
    WorkerHeartbeat::factory()->create(['last_seen_at' => now()->subSeconds(60)]);

    // 60s stale would be offline at the 5s-interval default (15s
    // threshold) but online at a 30s interval (90s threshold) —
    // proves the threshold actually moves with configuration.
    $presented = (new PresentWorkerAvailability)->present();

    expect($presented['online'])->toBeTrue();
});

it('reports the identity and worker_type of the most recently seen worker', function () {
    WorkerHeartbeat::factory()->create(['identity' => 'ai-box-browser-inspector', 'worker_type' => 'browser_inspector', 'last_seen_at' => now()->subMinutes(5)]);
    WorkerHeartbeat::factory()->create(['identity' => 'newer-worker', 'worker_type' => 'browser_inspector', 'last_seen_at' => now()]);

    $presented = (new PresentWorkerAvailability)->present();

    expect($presented['identity'])->toBe('newer-worker');
});
