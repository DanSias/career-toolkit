<?php

use App\Http\Middleware\AllowLongRunningGeneration;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/**
 * Proves App\Http\Middleware\AllowLongRunningGeneration actually
 * neutralizes PHP's ambient max_execution_time (the root cause of the
 * "Maximum execution time of 30+2 seconds exceeded" failure observed
 * under the `cli-server` SAPI `php artisan serve` runs under — see
 * that middleware's own docblock), and that it is applied to exactly
 * the two remaining synchronous generation POST routes that make a
 * blocking Ollama call, never to any GET/preview/download route. Job
 * Analysis's own POST route no longer needs it — that request now only
 * creates a GenerationAttempt and dispatches a queued job, returning
 * almost immediately; see docs/job-analysis-generation.md "Async Job
 * Analysis".
 */
it('sets the effective max_execution_time to 0, overriding a nonzero ambient limit', function () {
    $original = ini_get('max_execution_time');

    try {
        // A nonzero baseline — proves the middleware actually changes
        // something, rather than the assertion trivially passing
        // because the test-runner's own CLI SAPI already defaults to 0.
        ini_set('max_execution_time', '30');
        expect(ini_get('max_execution_time'))->toBe('30');

        (new AllowLongRunningGeneration)->handle(
            Request::create('/irrelevant', 'POST'),
            fn (Request $request) => response('ok'),
        );

        expect(ini_get('max_execution_time'))->toBe('0');
    } finally {
        // Never let this test's ini mutation leak into later tests in
        // the same process.
        ini_set('max_execution_time', $original);
    }
});

it('passes the request through to the next handler and returns its response unchanged', function () {
    $response = (new AllowLongRunningGeneration)->handle(
        Request::create('/irrelevant', 'POST'),
        fn (Request $request) => response('the next handler ran', 201),
    );

    expect($response->getStatusCode())->toBe(201)
        ->and($response->getContent())->toBe('the next handler ran');
});

it('is applied to exactly the two remaining synchronous generation POST routes that make a blocking Ollama call', function () {
    $generationRoutes = [
        'jobs.analyses.matches.store',
        'jobs.analyses.matches.resume.store',
    ];

    foreach ($generationRoutes as $name) {
        $route = Route::getRoutes()->getByName($name);
        expect($route)->not->toBeNull("Route [{$name}] was not found.")
            ->and($route->gatherMiddleware())->toContain(AllowLongRunningGeneration::class);
    }
});

it('is never applied to the GET review/preview/download routes, to job-posting intake, or to the now-queued Job Analysis POST', function () {
    $nonGenerationRoutes = [
        'jobs.store',                          // job-posting intake — no provider call
        'jobs.analyses.store',                  // now queues instead of calling a provider synchronously
        'jobs.analyses.show',                  // read-only review
        'jobs.analyses.matches.show',           // read-only review
        'jobs.analyses.matches.resume.show',    // read-only review
        'resume-variants.preview',              // pure render, no provider call
        'resume-variants.pdf',                  // pure render + validate, no provider call
    ];

    foreach ($nonGenerationRoutes as $name) {
        $route = Route::getRoutes()->getByName($name);
        expect($route)->not->toBeNull("Route [{$name}] was not found.")
            ->and($route->gatherMiddleware())->not->toContain(AllowLongRunningGeneration::class);
    }
});
