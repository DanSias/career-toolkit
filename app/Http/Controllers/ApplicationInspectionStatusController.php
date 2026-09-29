<?php

namespace App\Http\Controllers;

use App\Models\Application;
use App\Support\ApplicationInspection\PresentApplicationInspection;
use Illuminate\Http\JsonResponse;

/**
 * The one read-only polling surface the frontend uses to watch an
 * Application's inspection state while its latest WorkflowRun is
 * pending/running — mirrors
 * App\Http\Controllers\GenerationAttemptController's own established
 * convention exactly, including its no-auth-beyond-route-model-binding
 * posture (see that controller's docblock): this app has no login or
 * multi-tenant boundary anywhere.
 */
class ApplicationInspectionStatusController extends Controller
{
    public function show(Application $application, PresentApplicationInspection $presenter): JsonResponse
    {
        return response()->json($presenter->present($application));
    }
}
