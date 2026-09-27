<?php

namespace App\Http\Controllers;

use App\Enums\GenerationStatus;
use App\Enums\GenerationType;
use App\Enums\JobAnalysisFindingCategory;
use App\Jobs\GenerateJobMatchJob;
use App\Models\CareerFactMatch;
use App\Models\EducationMatch;
use App\Models\JobAnalysis;
use App\Models\JobMatch;
use App\Models\JobMatchFinding;
use App\Models\JobPosting;
use App\Models\ResumeVariant;
use App\Support\GenerationAttempt\PresentGenerationAttempt;
use App\Support\JobMatch\CareerFactAttribution;
use App\Support\ResumeVariant\DiscoveryPreflight;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Generates and displays JobMatch snapshots comparing a JobAnalysis
 * against the current CareerProfile. Generation always creates a new
 * immutable snapshot — never edits or replaces a prior one, and there
 * is no "current" pointer to manage. No editing, no deletion — a
 * read-only inspection surface over App\Support\JobMatch's generation
 * pipeline, mirroring JobAnalysisController exactly.
 *
 * store() itself never calls the generation pipeline — it only
 * creates/looks up a GenerationAttempt and dispatches
 * GenerateJobMatchJob, which owns the actual GenerateJobMatch::generate()
 * call and every outcome durably on that attempt. See "Async Job Match"
 * in docs/job-match-generation.md.
 */
class JobMatchController extends Controller
{
    public function store(JobPosting $jobPosting, JobAnalysis $jobAnalysis): RedirectResponse
    {
        abort_unless($jobAnalysis->job_posting_id === $jobPosting->id, 404);

        $hasActiveAttempt = $jobAnalysis->generationAttempts()
            ->active()
            ->where('generation_type', GenerationType::JobMatch)
            ->exists();

        if (! $hasActiveAttempt) {
            $attempt = $jobAnalysis->generationAttempts()->create([
                'generation_type' => GenerationType::JobMatch,
                'status' => GenerationStatus::Queued,
                'queued_at' => now(),
            ]);

            GenerateJobMatchJob::dispatch($attempt);
        }

        return redirect()->route('jobs.analyses.show', [$jobPosting, $jobAnalysis]);
    }

    public function show(JobPosting $jobPosting, JobAnalysis $jobAnalysis, JobMatch $jobMatch, PresentGenerationAttempt $presenter): Response
    {
        abort_unless($jobAnalysis->job_posting_id === $jobPosting->id, 404);
        abort_unless($jobMatch->job_analysis_id === $jobAnalysis->id, 404);

        $jobMatch->load([
            'findings.jobAnalysisFinding',
            'findings.careerFactMatches.careerFact',
            'findings.educationMatches.education',
        ]);

        return Inertia::render('jobs/matches/show', [
            'job' => [
                'id' => $jobPosting->id,
                'company' => $jobPosting->company,
                'title' => $jobPosting->title,
            ],
            'analysis' => [
                'id' => $jobAnalysis->id,
            ],
            'match' => $this->transformMatch($jobMatch, $presenter),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function transformMatch(JobMatch $match, PresentGenerationAttempt $presenter): array
    {
        return [
            'id' => $match->id,
            'generated_at' => $match->generated_at->toDateTimeString(),
            'generated_by' => $match->generated_by,
            'schema_version' => $match->schema_version,
            'prompt_version' => $match->prompt_version,
            'categories' => $this->groupFindingsByCategory($match->findings),
            'resume_variants' => $match->resumeVariants()
                ->latest('generated_at')
                ->get()
                ->map($this->transformResumeVariantSummary(...))
                ->all(),
            // Deterministic, no-provider-call gap detection — always
            // safe to compute and display; generating a resume never
            // requires acting on it. See docs/domain-model.md
            // "ResumeVariant".
            'discovery_preflight' => (new DiscoveryPreflight)->run($match),
            'latest_resume_attempt' => $this->latestUnresolvedResumeAttempt($match, $presenter),
        ];
    }

    /**
     * The most recent resume_variant GenerationAttempt for this match,
     * but only when it's still queued/running or ended in failure —
     * mirrors JobPostingController::latestUnresolvedAttempt() and
     * JobAnalysisController::latestUnresolvedMatchAttempt() exactly. A
     * succeeded attempt is omitted since its result already appears in
     * `resume_variants` above.
     *
     * @return array<string, mixed>|null
     */
    private function latestUnresolvedResumeAttempt(JobMatch $match, PresentGenerationAttempt $presenter): ?array
    {
        $attempt = $match->generationAttempts()
            ->where('generation_type', GenerationType::ResumeVariant)
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
    private function transformResumeVariantSummary(ResumeVariant $variant): array
    {
        return [
            'id' => $variant->id,
            'generated_at' => $variant->generated_at->toDateTimeString(),
        ];
    }

    /**
     * Groups JobMatchFindings by their JobAnalysisFinding's category, in
     * the enum's declared order — identical convention to
     * JobAnalysisController, so the two pages read consistently.
     *
     * @param  Collection<int, JobMatchFinding>  $findings
     * @return array<int, array<string, mixed>>
     */
    private function groupFindingsByCategory(Collection $findings): array
    {
        $byCategory = $findings->groupBy(
            fn (JobMatchFinding $finding) => $finding->jobAnalysisFinding->category->value
        );

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
    private function transformFinding(JobMatchFinding $matchFinding): array
    {
        $finding = $matchFinding->jobAnalysisFinding;

        return [
            'id' => $matchFinding->id,
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
            'coverage' => $matchFinding->coverage->value,
            'coverage_rationale' => $matchFinding->coverage_rationale,
            'career_fact_matches' => $matchFinding->careerFactMatches
                ->map($this->transformCareerFactMatch(...))
                ->all(),
            'education_matches' => $matchFinding->educationMatches
                ->map($this->transformEducationMatch(...))
                ->all(),
        ];
    }

    /**
     * Visibility is resolved live from the CareerFact relation, never
     * frozen at match-generation time — see docs/domain-model.md
     * "JobMatch" (`visibility` is the one deliberate exception to
     * snapshot-freezing).
     *
     * @return array<string, mixed>
     */
    private function transformCareerFactMatch(CareerFactMatch $match): array
    {
        $fact = $match->careerFact;
        $attribution = CareerFactAttribution::resolve($fact);

        return [
            'relationship' => $match->relationship->value,
            'rationale' => $match->rationale,
            'statement' => $fact->statement,
            'visibility' => $fact->visibility->value,
            'attribution' => [
                'employer' => $attribution->employer,
                'role' => $attribution->role,
                'project' => $attribution->project,
            ],
            'role_dates' => $attribution->roleStartYear === null ? null : [
                'start_year' => $attribution->roleStartYear,
                'start_month' => $attribution->roleStartMonth,
                'end_year' => $attribution->roleEndYear,
                'end_month' => $attribution->roleEndMonth,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function transformEducationMatch(EducationMatch $match): array
    {
        $education = $match->education;

        return [
            'relationship' => $match->relationship->value,
            'rationale' => $match->rationale,
            'institution' => $education->institution,
            'degree' => $education->degree,
            'field_of_study' => $education->field_of_study,
            'start_year' => $education->start_year,
            'end_year' => $education->end_year,
        ];
    }
}
