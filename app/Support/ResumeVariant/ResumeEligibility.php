<?php

namespace App\Support\ResumeVariant;

use App\Enums\Visibility;
use App\Models\CareerFact;
use App\Models\CareerProfile;
use App\Models\Project;
use Illuminate\Database\Eloquent\Builder;

/**
 * The single, deterministic boundary between canonical candidate data
 * and either resume-generation provider call — applied BEFORE either
 * provider ever sees a payload, never left to a prompt instruction
 * alone. See docs/domain-model.md "ResumeVariant" and the
 * visibility-curation review this milestone's data depends on
 * (commit 578caab).
 *
 * Eligible = Visibility::Public or Visibility::Restricted, excluding
 * Visibility::Private, PLUS the project-level backstop: a CareerFact
 * attributed to a Project whose own `default_visibility` is Private is
 * excluded even if the fact's own visibility is more permissive —
 * protecting the case docs/domain-model.md "Verification vs.
 * visibility" explicitly anticipated (a project whose existence itself
 * must stay non-exposed) but that, until this milestone, no consumer
 * actually enforced.
 *
 * Education carries no visibility field at all and is never gated
 * here — its absence of a CareerFact-style restriction is not a grant
 * of default inclusion; whether a given Education record appears in a
 * given resume remains a separate Selection-stage decision.
 */
final class ResumeEligibility
{
    /**
     * @return Builder<CareerFact>
     */
    public static function eligibleCareerFactsQuery(CareerProfile $profile): Builder
    {
        return CareerFact::query()
            ->where('career_profile_id', $profile->id)
            ->where('visibility', '!=', Visibility::Private->value)
            ->where(function (Builder $query) {
                $query->where('attributable_type', '!=', 'project')
                    ->orWhereHas('attributable', function (Builder $projectQuery) {
                        $projectQuery->where(function (Builder $inner) {
                            $inner->whereNull('default_visibility')
                                ->orWhere('default_visibility', '!=', Visibility::Private->value);
                        });
                    });
            });
    }

    public static function isEligible(CareerFact $fact): bool
    {
        if ($fact->visibility === Visibility::Private) {
            return false;
        }

        if ($fact->attributable_type === 'project') {
            $project = $fact->attributable;

            if ($project instanceof Project && $project->default_visibility === Visibility::Private) {
                return false;
            }
        }

        return true;
    }
}
