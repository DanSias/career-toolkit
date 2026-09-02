<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreJobPostingRequest;
use App\Models\JobAnalysis;
use App\Models\JobPosting;
use App\Support\CurrentCareerProfile;
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

    public function show(JobPosting $jobPosting): Response
    {
        return Inertia::render('jobs/show', [
            'job' => $this->transformDetail($jobPosting),
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
    private function transformDetail(JobPosting $job): array
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
        ];
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
