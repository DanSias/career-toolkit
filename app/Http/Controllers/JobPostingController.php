<?php

namespace App\Http\Controllers;

use App\Enums\GenerationStatus;
use App\Enums\GenerationType;
use App\Http\Requests\StoreJobPostingRequest;
use App\Models\GenerationAttempt;
use App\Models\JobAnalysis;
use App\Models\JobPosting;
use App\Support\CurrentCareerProfile;
use App\Support\GenerationAttempt\PresentGenerationAttempt;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Job intake: capturing a target job posting's verbatim source material.
 * No analysis, matching, or CareerFact selection happens here — see
 * docs/domain-model.md "JobPosting".
 */
class JobPostingController extends Controller
{
    public function index(): Response
    {
        $jobs = CurrentCareerProfile::resolve()
            ->jobPostings()
            ->latest()
            ->get();

        return Inertia::render('jobs/index', [
            'jobs' => $jobs->map($this->transformSummary(...))->all(),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('jobs/create');
    }

    public function store(StoreJobPostingRequest $request): RedirectResponse
    {
        $job = CurrentCareerProfile::resolve()
            ->jobPostings()
            ->create($request->validated());

        return redirect()->route('jobs.show', $job);
    }

    public function show(JobPosting $jobPosting, PresentGenerationAttempt $presenter): Response
    {
        return Inertia::render('jobs/show', [
            'job' => $this->transformDetail($jobPosting, $presenter),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function transformSummary(JobPosting $job): array
    {
        return [
            'id' => $job->id,
            'company' => $job->company,
            'title' => $job->title,
            'location' => $job->location,
            'has_source_url' => $job->source_url !== null,
            'source_url' => $job->source_url,
            'captured_at' => $job->created_at?->toDateString(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function transformDetail(JobPosting $job, PresentGenerationAttempt $presenter): array
    {
        return [
            'id' => $job->id,
            'company' => $job->company,
            'title' => $job->title,
            'location' => $job->location,
            'source_url' => $job->source_url,
            'captured_at' => $job->created_at?->toDateString(),
            'description' => $job->description,
            'analyses' => $job->jobAnalyses()
                ->withCount('findings')
                ->latest('generated_at')
                ->get()
                ->map($this->transformAnalysisSummary(...))
                ->all(),
            'latest_job_analysis_attempt' => $this->latestUnresolvedAttempt($job, $presenter),
            // At most one Application per JobPosting — see the
            // applications.job_posting_id unique constraint and
            // App\Support\ApplicationInspection\DispatchApplicationInspection.
            'application_id' => $job->applications()->value('id'),
        ];
    }

    /**
     * The most recent job_analysis GenerationAttempt for this posting,
     * but only when it's still queued/running or ended in failure — a
     * succeeded attempt is deliberately omitted, since its result
     * already appears in `analyses` above and re-surfacing it here
     * would be redundant. Lets the page show "generation in progress"
     * or "here's why the last attempt failed" immediately on load,
     * including after a reload — see docs/job-analysis-generation.md
     * "Async Job Analysis".
     *
     * @return array<string, mixed>|null
     */
    private function latestUnresolvedAttempt(JobPosting $job, PresentGenerationAttempt $presenter): ?array
    {
        /** @var GenerationAttempt|null $attempt */
        $attempt = $job->generationAttempts()
            ->where('generation_type', GenerationType::JobAnalysis)
            ->latest('id')
            ->first();

        if ($attempt === null || $attempt->status === GenerationStatus::Succeeded) {
            return null;
        }

        return $presenter->present($attempt);
    }

    /**
     * @return array<string, mixed>
     */
    private function transformAnalysisSummary(JobAnalysis $analysis): array
    {
        return [
            'id' => $analysis->id,
            'generated_at' => $analysis->generated_at->toDateTimeString(),
            'overall_seniority' => $analysis->overall_seniority?->value,
            'findings_count' => $analysis->findings_count,
        ];
    }
}
