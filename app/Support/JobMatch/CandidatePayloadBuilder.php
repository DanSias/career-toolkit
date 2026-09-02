<?php

namespace App\Support\JobMatch;

use App\Models\CareerFact;
use App\Models\CareerProfile;
use App\Models\Education;
use App\Models\Project;
use App\Models\Role;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Collection;

/**
 * Builds the normalized candidate payload for one CareerProfile — every
 * CareerFact and Education row belonging to it, reduced to exactly the
 * approved matcher-facing fields. This exact array (nothing more) is
 * both what gets embedded in the provider prompt and what gets frozen,
 * verbatim, into JobMatch.input_snapshot — see
 * docs/job-match-generation.md.
 *
 * Deliberately excludes: raw CareerFact Evidence (including any
 * superseded quoted wording), Verification, and every raw database id
 * (CareerFacts are referenced by their stable `key`; Education rows —
 * which have no natural key — by their database id, since Education is
 * never externally addressed the way CareerFact is). See
 * docs/job-match-contract.md "Candidate input contract".
 */
final class CandidatePayloadBuilder
{
    /**
     * @return array{career_facts: array<int, array<string, mixed>>, education: array<int, array<string, mixed>>}
     */
    public function build(CareerProfile $profile): array
    {
        return [
            'career_facts' => $this->buildCareerFacts($profile),
            'education' => $this->buildEducation($profile),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildCareerFacts(CareerProfile $profile): array
    {
        $facts = CareerFact::query()
            ->where('career_profile_id', $profile->id)
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

        return $facts->map(fn (CareerFact $fact) => $this->normalizeCareerFact($fact))->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeCareerFact(CareerFact $fact): array
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
            // Role.start_year is required whenever a Role is genuinely
            // part of the attribution chain, so its presence alone
            // reliably distinguishes "no Role in this chain" from "a
            // Role with an open-ended end date."
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
            'skills' => $fact->skills->map(fn ($skill) => [
                'name' => $skill->name,
                'category' => $skill->category->value,
            ])->all(),
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
}
