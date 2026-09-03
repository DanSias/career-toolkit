<?php

namespace App\Http\Controllers;

use App\Enums\ResumeClaimPosture;
use App\Enums\ResumeTermUsageLocation;
use App\Exceptions\InvalidResumeVariantResponseException;
use App\Exceptions\ResumeGenerationProviderException;
use App\Models\CareerFact;
use App\Models\Education;
use App\Models\JobAnalysis;
use App\Models\JobMatch;
use App\Models\JobPosting;
use App\Models\ResumeVariant;
use App\Models\ResumeVariantExperienceBullet;
use App\Models\ResumeVariantTargetTermUsage;
use App\Models\Role;
use App\Models\Skill;
use App\Support\JobMatch\CareerFactAttribution;
use App\Support\ResumeVariant\GenerateResumeVariant;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * Generates and displays ResumeVariant snapshots for one JobMatch.
 * Generation always creates a new immutable snapshot — never edits or
 * replaces a prior one, and there is no "current" pointer to manage.
 * No editing, no deletion, no PDF — a read-only inspection surface over
 * App\Support\ResumeVariant\GenerateResumeVariant's pipeline, mirroring
 * JobMatchController exactly. See docs/resume-variant-generation.md.
 */
class ResumeVariantController extends Controller
{
    public function store(
        JobPosting $jobPosting,
        JobAnalysis $jobAnalysis,
        JobMatch $jobMatch,
        GenerateResumeVariant $generator,
    ): RedirectResponse {
        abort_unless($jobAnalysis->job_posting_id === $jobPosting->id, 404);
        abort_unless($jobMatch->job_analysis_id === $jobAnalysis->id, 404);

        try {
            $variant = $generator->generateFull($jobMatch);
        } catch (ResumeGenerationProviderException|InvalidResumeVariantResponseException $e) {
            Log::warning('ResumeVariant generation failed.', [
                'job_match_id' => $jobMatch->id,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            return back()->withErrors([
                'resume_generation' => 'Resume generation failed — the response could not be validated. You can try again.',
            ]);
        } catch (Throwable $e) {
            Log::error('ResumeVariant generation failed unexpectedly.', [
                'job_match_id' => $jobMatch->id,
                'exception' => $e::class,
            ]);

            return back()->withErrors([
                'resume_generation' => 'Resume generation failed unexpectedly. You can try again.',
            ]);
        }

        return redirect()->route('jobs.analyses.matches.resume.show', [$jobPosting, $jobAnalysis, $jobMatch, $variant]);
    }

    public function show(
        JobPosting $jobPosting,
        JobAnalysis $jobAnalysis,
        JobMatch $jobMatch,
        ResumeVariant $resumeVariant,
    ): Response {
        abort_unless($jobAnalysis->job_posting_id === $jobPosting->id, 404);
        abort_unless($jobMatch->job_analysis_id === $jobAnalysis->id, 404);
        abort_unless($resumeVariant->job_match_id === $jobMatch->id, 404);

        $resumeVariant->load([
            'experienceBullets.citations.careerFact',
            'experienceBullets.targetTermUsages',
            'summaryEvidence.careerFact',
            'skillSelections.skill',
            'educationSelections.education',
            'targetTermUsages.jobAnalysisFinding',
            'targetTermUsages.evidence.careerFact',
        ]);

        return Inertia::render('jobs/resumes/show', [
            'job' => [
                'id' => $jobPosting->id,
                'company' => $jobPosting->company,
                'title' => $jobPosting->title,
            ],
            'analysis' => ['id' => $jobAnalysis->id],
            'match' => ['id' => $jobMatch->id],
            'resume' => $this->transformResumeVariant($resumeVariant),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function transformResumeVariant(ResumeVariant $variant): array
    {
        return [
            'id' => $variant->id,
            'generated_at' => $variant->generated_at->toDateTimeString(),
            'selection_generated_by' => $variant->selection_generated_by,
            'wording_generated_by' => $variant->wording_generated_by,
            'schema_version' => $variant->schema_version,
            'selection_prompt_version' => $variant->selection_prompt_version,
            'wording_prompt_version' => $variant->wording_prompt_version,
            'summary' => $variant->summary,
            'summary_evidence' => $variant->summaryEvidence
                ->map(fn ($evidence) => $this->transformCareerFactSummary($evidence->careerFact))
                ->all(),
            'summary_target_term_usages' => $variant->targetTermUsages
                ->filter(fn (ResumeVariantTargetTermUsage $usage) => $usage->location === ResumeTermUsageLocation::Summary)
                ->map($this->transformTargetTermUsage(...))
                ->values()
                ->all(),
            'experience' => $this->groupBulletsByRole($variant->experienceBullets),
            'skills' => $variant->skillSelections
                ->sortBy('display_order')
                ->map(fn ($selection) => $this->transformSkill($selection->skill))
                ->values()
                ->all(),
            'education' => $variant->educationSelections
                ->sortBy('display_order')
                ->map(fn ($selection) => $this->transformEducation($selection->education))
                ->values()
                ->all(),
        ];
    }

    /**
     * Roles are ordered deterministically by their real canonical start
     * date — reverse-chronological, exactly like a conventional resume
     * — never by anything the model decided. See docs/domain-model.md
     * "ResumeVariant".
     *
     * @param  Collection<int, ResumeVariantExperienceBullet>  $bullets
     * @return array<int, array<string, mixed>>
     */
    private function groupBulletsByRole(Collection $bullets): array
    {
        $roleIds = $bullets->pluck('role_id')->unique()->values();
        $roles = Role::query()->whereIn('id', $roleIds)->with('employer')->get()->keyBy('id');

        return $roles
            ->sortByDesc(fn (Role $role) => sprintf('%04d-%02d', $role->start_year, $role->start_month ?? 1))
            ->map(function (Role $role) use ($bullets) {
                $roleBullets = $bullets->where('role_id', $role->id)->sortBy('display_order');

                return [
                    'role_id' => $role->id,
                    'employer' => $role->employer->name,
                    'display_title' => $roleBullets->first()?->display_title,
                    'start_year' => $role->start_year,
                    'start_month' => $role->start_month,
                    'end_year' => $role->end_year,
                    'end_month' => $role->end_month,
                    'bullets' => $roleBullets->map($this->transformBullet(...))->values()->all(),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function transformBullet(ResumeVariantExperienceBullet $bullet): array
    {
        return [
            'id' => $bullet->id,
            'project' => $bullet->project?->name,
            'text' => $bullet->text,
            'citations' => $bullet->citations
                ->map(fn ($citation) => $this->transformCareerFactSummary($citation->careerFact))
                ->all(),
            'target_term_usages' => $bullet->targetTermUsages
                ->map($this->transformTargetTermUsage(...))
                ->values()
                ->all(),
        ];
    }

    /**
     * Visibility is resolved live from the CareerFact relation, never
     * frozen at generation time — same asymmetry as JobMatch. See
     * docs/domain-model.md "ResumeVariant".
     *
     * @return array<string, mixed>
     */
    private function transformCareerFactSummary(CareerFact $fact): array
    {
        $attribution = CareerFactAttribution::resolve($fact);

        return [
            'key' => $fact->key,
            'statement' => $fact->statement,
            'visibility' => $fact->visibility->value,
            'attribution' => [
                'employer' => $attribution->employer,
                'role' => $attribution->role,
                'project' => $attribution->project,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function transformTargetTermUsage(ResumeVariantTargetTermUsage $usage): array
    {
        return [
            'term' => $usage->target_term,
            'posture' => $usage->posture->value,
            'relationship_phrase' => $usage->posture === ResumeClaimPosture::Qualified
                ? $usage->relationship_phrase_key->render()
                : null,
            'job_analysis_finding_statement' => $usage->jobAnalysisFinding->statement,
            'evidence' => $usage->evidence
                ->map(fn ($evidence) => $this->transformCareerFactSummary($evidence->careerFact))
                ->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function transformSkill(Skill $skill): array
    {
        return [
            'id' => $skill->id,
            'name' => $skill->name,
            'category' => $skill->category->value,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function transformEducation(Education $education): array
    {
        return [
            'institution' => $education->institution,
            'degree' => $education->degree,
            'field_of_study' => $education->field_of_study,
            'start_year' => $education->start_year,
            'end_year' => $education->end_year,
        ];
    }
}
