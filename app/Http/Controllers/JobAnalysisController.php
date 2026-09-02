<?php

namespace App\Http\Controllers;

use App\Enums\JobAnalysisFindingCategory;
use App\Exceptions\InvalidJobAnalysisResponseException;
use App\Exceptions\JobAnalysisProviderException;
use App\Models\JobAnalysis;
use App\Models\JobAnalysisFinding;
use App\Models\JobAnalysisFindingEvidence;
use App\Models\JobMatch;
use App\Models\JobPosting;
use App\Support\CurrentCareerProfile;
use App\Support\JobAnalysis\GenerateJobAnalysis;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * Generates and displays JobAnalysis snapshots for a JobPosting.
 * Generation always creates a new immutable snapshot — never edits or
 * replaces a prior one, and there is no "current" pointer to manage.
 * No editing, no deletion, no matching/fit content — this is a
 * read-only inspection surface over App\Support\JobAnalysis's
 * generation pipeline. See docs/job-analysis-generation.md.
 */
class JobAnalysisController extends Controller
{
    public function store(JobPosting $jobPosting, GenerateJobAnalysis $generator): RedirectResponse
    {
        try {
            $analysis = $generator->generate($jobPosting);
        } catch (JobAnalysisProviderException|InvalidJobAnalysisResponseException $e) {
            Log::warning('JobAnalysis generation failed.', [
                'job_posting_id' => $jobPosting->id,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            return back()->withErrors([
                'generation' => 'Analysis generation failed — the response could not be validated. You can try again.',
            ]);
        } catch (Throwable $e) {
            Log::error('JobAnalysis generation failed unexpectedly.', [
                'job_posting_id' => $jobPosting->id,
                'exception' => $e::class,
            ]);

            return back()->withErrors([
                'generation' => 'Analysis generation failed unexpectedly. You can try again.',
            ]);
        }

        return redirect()->route('jobs.analyses.show', [$jobPosting, $analysis]);
    }

    public function show(JobPosting $jobPosting, JobAnalysis $jobAnalysis): Response
    {
        abort_unless($jobAnalysis->job_posting_id === $jobPosting->id, 404);

        $jobAnalysis->load('findings.evidence');

        return Inertia::render('jobs/analyses/show', [
            'job' => [
                'id' => $jobPosting->id,
                'company' => $jobPosting->company,
                'title' => $jobPosting->title,
            ],
            'analysis' => $this->transformAnalysis($jobAnalysis),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function transformAnalysis(JobAnalysis $analysis): array
    {
        return [
            'id' => $analysis->id,
            'generated_at' => $analysis->generated_at->toDateTimeString(),
            'generated_by' => $analysis->generated_by,
            'schema_version' => $analysis->schema_version,
            'prompt_version' => $analysis->prompt_version,
            'role_summary' => $analysis->role_summary,
            'overall_seniority' => $analysis->overall_seniority?->value,
            'seniority_rationale' => $analysis->seniority_rationale,
            'categories' => $this->groupFindingsByCategory($analysis->findings),
            'matches' => $analysis->jobMatches()
                ->where('career_profile_id', CurrentCareerProfile::resolve()->id)
                ->withCount('findings')
                ->latest('generated_at')
                ->get()
                ->map($this->transformMatchSummary(...))
                ->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function transformMatchSummary(JobMatch $match): array
    {
        return [
            'id' => $match->id,
            'generated_at' => $match->generated_at->toDateTimeString(),
            'findings_count' => $match->findings_count,
        ];
    }

    /**
     * Groups findings by category in the enum's declared order (not
     * arrival order or alphabetical) so the page reads in a consistent,
     * designed sequence every time.
     *
     * @param  Collection<int, JobAnalysisFinding>  $findings
     * @return array<int, array<string, mixed>>
     */
    private function groupFindingsByCategory(Collection $findings): array
    {
        $byCategory = $findings->groupBy(fn (JobAnalysisFinding $finding) => $finding->category->value);

        return collect(JobAnalysisFindingCategory::cases())
            ->filter(fn (JobAnalysisFindingCategory $category) => $byCategory->has($category->value))
            ->map(fn (JobAnalysisFindingCategory $category) => [
                'category' => $category->value,
                'findings' => $byCategory->get($category->value)
                    ->map($this->transformFinding(...))
                    ->values()
                    ->all(),
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function transformFinding(JobAnalysisFinding $finding): array
    {
        return [
            'id' => $finding->id,
            'statement' => $finding->statement,
            'label' => $finding->label,
            'basis' => $finding->basis->value,
            'requirement_strength' => $finding->requirement_strength?->value,
            'emphasis' => $finding->emphasis?->value,
            'maturity' => $finding->maturity?->value,
            'years_experience_min' => $finding->years_experience_min,
            'years_experience_max' => $finding->years_experience_max,
            'recency_requirement' => $finding->recency_requirement,
            'time_horizon' => $finding->time_horizon,
            'notes' => $finding->notes,
            'evidence' => $finding->evidence->map(fn (JobAnalysisFindingEvidence $evidence) => [
                'excerpt' => $evidence->excerpt,
                'source_section' => $evidence->source_section,
                'source_locator' => $evidence->source_locator,
            ])->all(),
        ];
    }
}
