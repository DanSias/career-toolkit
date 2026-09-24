<?php

namespace App\Support\ResumeVariant;

use App\Models\CareerFact;
use App\Models\CareerFactMatch;
use App\Models\CareerProfile;
use App\Models\Education;
use App\Models\JobMatch;
use App\Models\Project;
use App\Models\Role;
use App\Models\Skill;
use App\Support\JobMatch\CareerFactAttribution;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Collection;

/**
 * Builds the normalized candidate payload for Resume Selection — the
 * FULL currently resume-eligible canonical corpus (see
 * ResumeEligibility), never bounded to what a specific JobMatch
 * happened to cite. JobMatch is annotation layered on top of each
 * fact, not a recall ceiling: Selection may choose a fact JobMatch
 * never linked to any finding when it strengthens the resume through
 * impact, scope, ownership, seniority, breadth, technical
 * sophistication, production responsibility, or business results. See
 * docs/domain-model.md "ResumeVariant".
 *
 * Deliberately excludes: JobMatchFinding.coverage_rationale,
 * CareerFactMatch.rationale, EducationMatch.rationale — rationale is
 * never a factual source for generated resume wording, so it never
 * enters this payload in the first place. Also excludes raw
 * CareerFact Evidence and Verification, same as CandidatePayloadBuilder.
 */
final class ResumeCandidatePayloadBuilder
{
    /**
     * @return array{career_facts: array<int, array<string, mixed>>, education: array<int, array<string, mixed>>, eligible_skills: array<int, array<string, mixed>>, role_project_map: array<int, array{role_id: int, valid_project_ids: array<int, int>}>}
     */
    public function build(JobMatch $jobMatch): array
    {
        $profile = $jobMatch->careerProfile;

        $facts = ResumeEligibility::eligibleCareerFactsQuery($profile)
            ->with([
                'metric',
                'skills',
                'attributable' => function (Relation $relation) {
                    /** @var MorphTo<Model, CareerFact> $relation */
                    $relation->morphWith([
                        Role::class => ['employer'],
                        Project::class => ['role.employer'],
                    ]);
                },
            ])
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        $annotationsByFactId = $this->buildAnnotations($jobMatch);

        return [
            'career_facts' => $facts->map(fn (CareerFact $fact) => $this->normalizeCareerFact($fact, $annotationsByFactId[$fact->id] ?? []))->all(),
            'education' => $this->buildEducation($profile),
            'eligible_skills' => $this->buildEligibleSkills($facts),
            'role_project_map' => $this->buildRoleProjectMap($profile),
        ];
    }

    /**
     * One or more JobMatch annotations per CareerFact id — every
     * (finding, coverage, relationship, requirement_strength, emphasis)
     * combination JobMatch already recorded for that fact within this
     * specific match. A fact JobMatch never cited simply has none.
     *
     * A plain array, not a Collection: Collection's TValue template is
     * not covariant, which makes an exact-match return-type annotation
     * for a grouped/mapped shape like this needlessly brittle — a plain
     * array sidesteps that entirely.
     *
     * @return array<int, array<int, array{job_analysis_finding_id: int, coverage: string, relationship: string, requirement_strength: string|null, emphasis: string|null}>>
     */
    private function buildAnnotations(JobMatch $jobMatch): array
    {
        $matches = CareerFactMatch::query()
            ->whereHas('jobMatchFinding', fn ($query) => $query->where('job_match_id', $jobMatch->id))
            ->with(['jobMatchFinding.jobAnalysisFinding'])
            ->get();

        $annotationsByFactId = [];

        foreach ($matches as $match) {
            $finding = $match->jobMatchFinding;
            $jobAnalysisFinding = $finding->jobAnalysisFinding;

            $annotationsByFactId[$match->career_fact_id][] = [
                'job_analysis_finding_id' => $jobAnalysisFinding->id,
                'coverage' => $finding->coverage->value,
                'relationship' => $match->relationship->value,
                'requirement_strength' => $jobAnalysisFinding->requirement_strength?->value,
                'emphasis' => $jobAnalysisFinding->emphasis?->value,
            ];
        }

        return $annotationsByFactId;
    }

    /**
     * @param  array<int, array<string, mixed>>  $jobMatchAnnotations
     * @return array<string, mixed>
     */
    private function normalizeCareerFact(CareerFact $fact, array $jobMatchAnnotations): array
    {
        $attribution = CareerFactAttribution::resolve($fact);
        $ids = $this->resolveRoleAndProjectIds($fact);

        return [
            'key' => $fact->key,
            'statement' => $fact->statement,
            'fact_type' => $fact->fact_type->value,
            'attribution' => [
                'employer' => $attribution->employer,
                'role_id' => $ids['role_id'],
                'role' => $attribution->role,
                'project_id' => $ids['project_id'],
                'project' => $attribution->project,
            ],
            'role_dates' => $attribution->roleStartYear === null ? null : [
                'start_year' => $attribution->roleStartYear,
                'start_month' => $attribution->roleStartMonth,
                'end_year' => $attribution->roleEndYear,
                'end_month' => $attribution->roleEndMonth,
            ],
            'metric' => $fact->metric === null ? null : [
                'value' => (float) $fact->metric->value,
                'value_max' => $fact->metric->value_max === null ? null : (float) $fact->metric->value_max,
                'unit' => $fact->metric->unit,
                'comparator' => $fact->metric->comparator?->value,
                'scope_note' => $fact->metric->scope_note,
                'guardrail' => $fact->metric->guardrail,
            ],
            'skills' => $fact->skills->map(fn (Skill $skill) => [
                'id' => $skill->id,
                'name' => $skill->name,
                'category' => $skill->category->value,
            ])->all(),
            'job_match_annotations' => $jobMatchAnnotations,
        ];
    }

    /**
     * Grounds the model-facing `role_id`/`project_id` fields directly
     * against the fact's real, loaded `attributable` — never derived
     * from array position, chronology, name matching, or any other
     * inference. Added after live evaluation showed Resume Selection
     * repeatedly mis-binding two same-employer sibling roles' titles/
     * projects: `role_id`/`project_id` are the only identifiers in
     * this payload with no textual grounding anywhere the model can
     * read them (unlike `career_fact_key`, which is a stable, already-
     * correct, self-labeling string the model never got wrong across
     * either failed attempt) — see docs/resume-variant-generation.md
     * "Live evaluation". Local to this builder only; the shared
     * CareerFactAttribution/JobMatch payload are untouched.
     *
     * @return array{role_id: int|null, project_id: int|null}
     */
    private function resolveRoleAndProjectIds(CareerFact $fact): array
    {
        $attributable = $fact->attributable;

        if ($attributable instanceof Role) {
            return ['role_id' => $attributable->id, 'project_id' => null];
        }

        if ($attributable instanceof Project) {
            return ['role_id' => $attributable->role_id, 'project_id' => $attributable->id];
        }

        return ['role_id' => null, 'project_id' => null];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildEducation(CareerProfile $profile): array
    {
        /** @var Collection<int, Education> $education */
        $education = Education::query()
            ->where('career_profile_id', $profile->id)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        return $education->map(fn (Education $row) => [
            'id' => $row->id,
            'institution' => $row->institution,
            'degree' => $row->degree,
            'field_of_study' => $row->field_of_study,
            'start_year' => $row->start_year,
            'end_year' => $row->end_year,
        ])->all();
    }

    /**
     * Every canonical Skill attached to at least one eligible CareerFact
     * — a bare Skill with no supporting CareerFact never appears here.
     * See docs/domain-model.md "ResumeVariant" Skills eligibility rule.
     *
     * @param  Collection<int, CareerFact>  $eligibleFacts
     * @return array<int, array<string, mixed>>
     */
    private function buildEligibleSkills(Collection $eligibleFacts): array
    {
        return $eligibleFacts
            ->flatMap(fn (CareerFact $fact) => $fact->skills)
            ->unique('id')
            ->sortBy('name')
            ->values()
            ->map(fn (Skill $skill) => [
                'id' => $skill->id,
                'name' => $skill->name,
                'category' => $skill->category->value,
            ])
            ->all();
    }

    /**
     * The deterministic, machine-readable authority for which
     * `project_id` values are legal for which `role_id` in Resume
     * Selection's response — added after a live qwen3.8:27b evaluation
     * showed the model cannot reliably reconstruct role/project
     * ownership purely by scanning CareerFact attribution across a
     * large flat corpus (it borrowed a sibling role's project id
     * across an employer boundary, and the same looseness produced
     * further same-role cross-project fact mismatches the validator
     * did not previously check for — see
     * ResumeSelectionResponseValidator::assertExperienceFactProjectConsistency()).
     * Every `project_id` listed here for a role is exactly the set
     * `GenerateResumeVariant`'s own schema construction already treats
     * as legal for that role (`Role::projects()`, unfiltered by
     * visibility — matching the schema's existing behavior for
     * role-owned projects exactly) — this restates data the
     * application already has with certainty; it does not introduce a
     * new eligibility rule. `-1` (the existing "no specific project"
     * sentinel — see `ResumeSelectionPromptV2::jsonSchema()`) always
     * appears first for every role, including roles with no owned
     * projects at all, so absence of projects is stated positively
     * rather than left for the model to infer from never seeing a
     * project attribution.
     *
     * @return array<int, array{role_id: int, valid_project_ids: array<int, int>}>
     */
    private function buildRoleProjectMap(CareerProfile $profile): array
    {
        $roles = Role::query()
            ->whereHas('employer', fn ($query) => $query->where('career_profile_id', $profile->id))
            ->with('projects')
            ->orderBy('id')
            ->get();

        return $roles->map(fn (Role $role) => [
            'role_id' => $role->id,
            'valid_project_ids' => [-1, ...$role->projects->sortBy('id')->pluck('id')->all()],
        ])->all();
    }
}
