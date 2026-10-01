<?php

namespace App\Http\Controllers;

use App\Enums\DescriptionCompleteness;
use App\Enums\GenerationStatus;
use App\Enums\GenerationType;
use App\Enums\JobAnalysisFindingCategory;
use App\Jobs\GenerateJobAnalysisJob;
use App\Models\JobAnalysis;
use App\Models\JobAnalysisFinding;
use App\Models\JobAnalysisFindingEvidence;
use App\Models\JobMatch;
use App\Models\JobPosting;
use App\Support\CurrentCareerProfile;
use App\Support\GenerationAttempt\PresentGenerationAttempt;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Triggers and displays JobAnalysis snapshots for a JobPosting.
 * Generation always creates a new immutable snapshot — never edits or
 * replaces a prior one, and there is no "current" pointer to manage.
 * No editing, no deletion, no matching/fit content — this is a
 * read-only inspection surface over App\Support\JobAnalysis's
 * generation pipeline. See docs/job-analysis-generation.md.
 *
 * store() itself never calls the generation pipeline — it only
 * creates/looks up a GenerationAttempt and dispatches
 * GenerateJobAnalysisJob, which owns the actual
 * GenerateJobAnalysis::generate() call and every outcome (success,
 * provider failure, validation failure, evidence-verification failure,
 * unexpected failure) durably on that attempt. See "Async Job
 * Analysis" in docs/job-analysis-generation.md.
 */
class JobAnalysisController extends Controller
{
    public function store(Request $request, JobPosting $jobPosting): RedirectResponse
    {
        $request->validate(['allow_incomplete_description' => ['sometimes', 'boolean']]);

        if ($jobPosting->description_completeness !== DescriptionCompleteness::Complete
            && ! $request->boolean('allow_incomplete_description')) {
            throw ValidationException::withMessages([
                'allow_incomplete_description' => 'The description may be incomplete. Missing requirements or responsibilities can affect analysis. Choose Analyze Anyway to continue.',
            ]);
        }

        $hasActiveAttempt = $jobPosting->generationAttempts()
            ->active()
            ->where('generation_type', GenerationType::JobAnalysis)
            ->exists();

        if (! $hasActiveAttempt) {
            $attempt = $jobPosting->generationAttempts()->create([
                'generation_type' => GenerationType::JobAnalysis,
                'status' => GenerationStatus::Queued,
                'queued_at' => now(),
            ]);

            GenerateJobAnalysisJob::dispatch($attempt);
        }

        return redirect()->route('jobs.show', $jobPosting);
    }

    public function show(JobPosting $jobPosting, JobAnalysis $jobAnalysis, PresentGenerationAttempt $presenter): Response
    {
        abort_unless($jobAnalysis->job_posting_id === $jobPosting->id, 404);

        $jobAnalysis->load('findings.evidence');

        return Inertia::render('jobs/analyses/show', [
            'job' => [
                'id' => $jobPosting->id,
                'company' => $jobPosting->company,
                'title' => $jobPosting->title,
            ],
            'analysis' => $this->transformAnalysis($jobAnalysis, $presenter),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function transformAnalysis(JobAnalysis $analysis, PresentGenerationAttempt $presenter): array
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
            'latest_job_match_attempt' => $this->latestUnresolvedMatchAttempt($analysis, $presenter),
        ];
    }

    /**
     * The most recent job_match GenerationAttempt for this analysis,
     * but only when it's still queued/running or ended in failure —
     * mirrors JobPostingController::latestUnresolvedAttempt() exactly.
     * A succeeded attempt is omitted since its result already appears
     * in `matches` above.
     *
     * @return array<string, mixed>|null
     */
    private function latestUnresolvedMatchAttempt(JobAnalysis $analysis, PresentGenerationAttempt $presenter): ?array
    {
        $attempt = $analysis->generationAttempts()
            ->where('generation_type', GenerationType::JobMatch)
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
