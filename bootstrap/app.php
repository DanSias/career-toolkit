<?php

use App\Http\Middleware\HandleInertiaRequests;
use App\Support\JobDiscovery\DiscoveryScheduleIsDue;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withSchedule(function (Schedule $schedule): void {
        // Checked hourly, but only actually runs discovery when
        // DiscoveryScheduleIsDue says the last successful DiscoveryRun
        // is stale — one mechanism covers both the normal daily
        // cadence and "the app wasn't running at the usual time, catch
        // up now" behavior, rather than two separate schedule entries.
        // See docs/job-discovery.md "Scheduling / orchestration" and
        // App\Providers\AppServiceProvider for how `schedule:work`
        // itself starts automatically alongside `composer run dev`.
        $schedule->command('discovery:run')
            ->hourly()
            ->withoutOverlapping()
            ->when(fn (): bool => (new DiscoveryScheduleIsDue)());
    })
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);

        // JobPosting.description must stay byte-for-byte verbatim — the
        // framework's default global TrimStrings middleware would
        // otherwise trim its leading/trailing whitespace like any other
        // input. Ordinary single-line fields (company, title, location,
        // source_url) still get trimmed normally. See
        // docs/domain-model.md "JobPosting".
        $middleware->trimStrings(except: ['description']);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
