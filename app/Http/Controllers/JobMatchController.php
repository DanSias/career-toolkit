<?php

namespace App\Http\Controllers;

use App\Enums\JobAnalysisFindingCategory;
use App\Exceptions\InvalidJobMatchResponseException;
use App\Exceptions\JobMatchProviderException;
use App\Models\CareerFactMatch;
use App\Models\EducationMatch;
use App\Models\JobAnalysis;
use App\Models\JobMatch;
use App\Models\JobMatchFinding;
use App\Models\JobPosting;
use App\Support\CurrentCareerProfile;
use App\Support\JobMatch\CareerFactAttribution;
use App\Support\JobMatch\GenerateJobMatch;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * Generates and displays JobMatch snapshots comparing a JobAnalysis
 * against the current CareerProfile. Generation always creates a new
 * immutable snapshot — never edits or replaces a prior one, and there
 * is no "current" pointer to manage. No editing, no deletion — a
 * read-only inspection surface over App\Support\JobMatch's generation
 * pipeline, mirroring JobAnalysisController exactly. See
 * docs/job-match-generation.md.
 */
class JobMatchController extends Controller
{
    public function store(JobPosting $jobPosting, JobAnalysis $jobAnalysis, GenerateJobMatch $generator): RedirectResponse
    {
        abort_unless($jobAnalysis->job_posting_id === $jobPosting->id, 404);

        $profile = CurrentCareerProfile::resolve();

        try {
            $match = $generator->generate($jobAnalysis, $profile);
        } catch (JobMatchProviderException|InvalidJobMatchResponseException $e) {
            Log::warning('JobMatch generation failed.', [
                'job_analysis_id' => $jobAnalysis->id,
                'career_profile_id' => $profile->id,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            return back()->withErrors([
                'match_generation' => 'Match generation failed — the response could not be validated. You can try again.',
            ]);
        } catch (Throwable $e) {
            Log::error('JobMatch generation failed unexpectedly.', [
                'job_analysis_id' => $jobAnalysis->id,
                'career_profile_id' => $profile->id,
                'exception' => $e::class,
            ]);

            return back()->withErrors([
                'match_generation' => 'Match generation failed unexpectedly. You can try again.',
            ]);
        }

        return redirect()->route('jobs.analyses.matches.show', [$jobPosting, $jobAnalysis, $match]);
    }

    public function show(JobPosting $jobPosting, JobAnalysis $jobAnalysis, JobMatch $jobMatch): Response
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
            'match' => $this->transformMatch($jobMatch),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function transformMatch(JobMatch $match): array
    {
        return [
            'id' => $match->id,
            'generated_at' => $match->generated_at->toDateTimeString(),
            'generated_by' => $match->generated_by,
            'schema_version' => $match->schema_version,
            'prompt_version' => $match->prompt_version,
            'categories' => $this->groupFindingsByCategory($match->findings),
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
