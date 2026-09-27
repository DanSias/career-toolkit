<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Removes PHP's own ambient `max_execution_time` for the specific
 * routes that make one blocking, potentially multi-minute local Ollama
 * inference call — so each provider client's own explicit
 * `Http::timeout()` value (300s for Job Analysis/Job Match, 900s for
 * Resume Selection/Wording) remains the only meaningful upper bound,
 * rather than an unrelated, much shorter PHP interpreter limit.
 *
 * Root cause this addresses: `php artisan serve` runs the request
 * under PHP's `cli-server` SAPI, which — unlike the plain `cli` SAPI
 * `php --ini`/`php -r` report from — does NOT get `max_execution_time`
 * overridden to 0. It enforces whatever `php.ini` actually says (30 by
 * default), independent of and much shorter than the provider timeouts
 * configured in this application. `set_time_limit(0)` here removes
 * that PHP-level ceiling for exactly these requests, without touching
 * `php.ini`, `.env`, or any provider timeout configuration, and without
 * introducing a queue — the existing synchronous, all-or-nothing
 * generation architecture is otherwise unchanged. See
 * docs/resume-variant-generation.md and docs/job-analysis-generation.md
 * for the generation pipelines this protects.
 *
 * Deliberately applied only to the three generation POST routes (Job
 * Analysis, Job Match, Resume Variant) — never globally — so ordinary
 * fast routes keep PHP's normal runaway-script protection.
 */
class AllowLongRunningGeneration
{
    public function handle(Request $request, Closure $next): Response
    {
        set_time_limit(0);

        return $next($request);
    }
}
