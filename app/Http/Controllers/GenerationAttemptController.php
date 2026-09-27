<?php

namespace App\Http\Controllers;

use App\Models\GenerationAttempt;
use App\Support\GenerationAttempt\PresentGenerationAttempt;
use Illuminate\Http\JsonResponse;

/**
 * The one read-only polling surface the frontend uses to watch a
 * generation attempt's durable status while it's queued/running — see
 * docs/job-analysis-generation.md "Durable generation attempts
 * (foundation)". No auth/ownership check beyond normal route-model
 * binding: this app has no login or multi-tenant boundary anywhere
 * (see App\Support\CurrentCareerProfile's own docblock), so there is
 * nothing to check beyond "does this id exist" — the same posture
 * every other show route in this app already has.
 */
class GenerationAttemptController extends Controller
{
    public function show(GenerationAttempt $generationAttempt, PresentGenerationAttempt $presenter): JsonResponse
    {
        return response()->json($presenter->present($generationAttempt));
    }
}
