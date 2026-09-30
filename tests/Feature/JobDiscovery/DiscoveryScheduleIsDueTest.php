<?php

use App\Enums\DiscoveryStatus;
use App\Models\DiscoveryRun;
use App\Support\JobDiscovery\DiscoveryScheduleIsDue;
use Carbon\CarbonImmutable;

beforeEach(function () {
    config(['job_discovery.schedule.stale_after_hours' => 23]);
});

afterEach(fn () => CarbonImmutable::setTestNow());

it('is due when no DiscoveryRun has ever succeeded', function () {
    expect((new DiscoveryScheduleIsDue)())->toBeTrue();
});

it('is not due when the last successful run is recent', function () {
    DiscoveryRun::factory()->create(['status' => DiscoveryStatus::Succeeded, 'finished_at' => now()->subHour()]);

    expect((new DiscoveryScheduleIsDue)())->toBeFalse();
});

it('is due when the last successful run exceeds the configured staleness threshold', function () {
    DiscoveryRun::factory()->create(['status' => DiscoveryStatus::Succeeded, 'finished_at' => now()->subHours(24)]);

    expect((new DiscoveryScheduleIsDue)())->toBeTrue();
});

it('is not due exactly at the configured threshold boundary', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-06-01 12:00:00'));
    DiscoveryRun::factory()->create(['status' => DiscoveryStatus::Succeeded, 'finished_at' => now()->subHours(23)]);

    expect((new DiscoveryScheduleIsDue)())->toBeFalse();
});

it('ignores a failed-to-even-complete run and only considers the last succeeded one', function () {
    DiscoveryRun::factory()->create(['status' => DiscoveryStatus::Succeeded, 'finished_at' => now()->subHour()]);
    DiscoveryRun::factory()->create(['status' => DiscoveryStatus::Failed, 'finished_at' => now()]);

    expect((new DiscoveryScheduleIsDue)())->toBeFalse();
});

it('respects a reconfigured staleness threshold', function () {
    config(['job_discovery.schedule.stale_after_hours' => 1]);
    DiscoveryRun::factory()->create(['status' => DiscoveryStatus::Succeeded, 'finished_at' => now()->subMinutes(90)]);

    expect((new DiscoveryScheduleIsDue)())->toBeTrue();
});
