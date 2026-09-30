<?php

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;

// bootstrap/app.php's withSchedule() callback only actually runs via
// Artisan::starting (see Illuminate\Foundation\Configuration\
// ApplicationBuilder::withSchedule()) — never during a normal test
// boot — so every test here first fires a real (harmless) artisan
// command to trigger it before resolving Schedule::class.
beforeEach(fn () => Artisan::call('list'));

it('registers discovery:run on the schedule with overlap prevention', function () {
    $schedule = app(Schedule::class);
    $events = $schedule->events();

    $discoveryEvent = collect($events)->first(fn ($event) => str_contains($event->command ?? '', 'discovery:run'));

    expect($discoveryEvent)->not->toBeNull()
        ->and($discoveryEvent->expression)->toBe('0 * * * *')
        ->and($discoveryEvent->withoutOverlapping)->toBeTrue();
});

it('invokes the same discovery:run command the manual trigger uses', function () {
    // Both the manual `php artisan discovery:run` and the scheduled
    // entry are the SAME artisan command — see
    // App\Console\Commands\RunJobDiscoveryCommand's own docblock — so
    // this is really just confirming the schedule targets that exact
    // command string, not a separate implementation.
    $schedule = app(Schedule::class);
    $commands = collect($schedule->events())->pluck('command')->filter(fn ($c) => str_contains($c, 'discovery:run'));

    expect($commands)->toHaveCount(1);
});
