<?php

namespace App\Http\Controllers;

use App\Models\Application;
use App\Models\JobPosting;
use App\Support\ApplicationInspection\DispatchApplicationInspection;
use App\Support\ApplicationInspection\PresentApplicationInspection;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

/**
 * "Inspect Application" and its read-only results page — see
 * docs/application-inspector.md. store() never runs an inspection
 * itself; it only dispatches the durable workflow (see
 * App\Support\ApplicationInspection\DispatchApplicationInspection) and
 * redirects, mirroring JobAnalysisController::store()'s existing
 * thin-controller convention exactly.
 */
class ApplicationController extends Controller
{
    public function store(JobPosting $jobPosting, DispatchApplicationInspection $dispatcher): RedirectResponse
    {
        try {
            $workflowRun = $dispatcher->dispatch($jobPosting);
        } catch (InvalidArgumentException $e) {
            abort(422, $e->getMessage());
        }

        return redirect()->route('applications.show', $workflowRun->application);
    }

    public function show(Application $application, PresentApplicationInspection $presenter): Response
    {
        $application->load('jobPosting');

        return Inertia::render('applications/show', [
            'application' => [
                'id' => $application->id,
                'job_posting' => [
                    'id' => $application->jobPosting->id,
                    'company' => $application->jobPosting->company,
                    'title' => $application->jobPosting->title,
                    'source_url' => $application->jobPosting->source_url,
                ],
            ],
            'inspection' => $presenter->present($application),
        ]);
    }
}
