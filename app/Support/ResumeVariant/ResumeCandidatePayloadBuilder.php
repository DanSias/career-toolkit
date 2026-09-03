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
     * @return array{career_facts: array<int, array<string, mixed>>, education: array<int, array<string, mixed>>, eligible_skills: array<int, array<string, mixed>>}
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

        return [
            'key' => $fact->key,
            'statement' => $fact->statement,
            'fact_type' => $fact->fact_type->value,
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
}
