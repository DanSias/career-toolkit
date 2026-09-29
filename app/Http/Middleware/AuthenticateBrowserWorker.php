<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guards the worker protocol endpoints only (routes/api.php) — a
 * single shared-secret Bearer token, not Laravel Sanctum. This
 * repository has no auth infrastructure of any kind otherwise;
 * Sanctum's differentiating value (multiple scoped tokens,
 * database-backed per-token abilities, self-service revocation across
 * many identities) isn't exercised by exactly one static worker
 * identity calling exactly two endpoints. See
 * docs/application-inspector.md "Approved v1 worker authentication"
 * for the upgrade triggers that would justify revisiting this.
 *
 * Fails closed: an unconfigured server-side token rejects every
 * request rather than silently accepting any/no credential.
 */
class AuthenticateBrowserWorker
{
    public function handle(Request $request, Closure $next): Response
    {
        $configured = (string) config('services.browser_worker.token');

        if ($configured === '') {
            abort(401, 'Browser worker authentication is not configured.');
        }

        $provided = (string) $request->bearerToken();

        if ($provided === '' || ! hash_equals($configured, $provided)) {
            abort(401, 'Invalid worker credentials.');
        }

        return $next($request);
    }
}
