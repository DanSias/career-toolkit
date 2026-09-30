<?php

namespace App\Http\Controllers;

use App\Support\ApplicationInspection\PresentWorkerAvailability;
use Illuminate\Http\JsonResponse;

/**
 * A small, read-only status feed for the nav-bar worker badge and
 * anywhere else "is inspection capacity available" needs answering
 * (e.g. an Application inspection page showing "waiting for browser
 * worker" while queued). Career Toolkit only ever OBSERVES the
 * worker here — no start/stop/restart action exists, and none is
 * planned; see docs/application-inspector.md "Worker presence".
 */
class BrowserWorkerStatusController extends Controller
{
    public function show(PresentWorkerAvailability $presenter): JsonResponse
    {
        return response()->json($presenter->present());
    }
}
