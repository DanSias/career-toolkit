<?php

namespace App\Support\ResumeVariant;

use App\Enums\MatchRelationship;
use App\Models\CareerFactMatch;
use App\Models\JobAnalysisFinding;
use App\Models\JobMatch;

/**
 * Whether canonical evidence authorizes a `direct` resume claim ("I
 * have experience with X") for a given target term — deliberately NOT
 * lexical occurrence alone. Mirrors JobMatch's own rejection of bare
 * Skill tags as independent evidence, applied at the exact point where
 * ResumeVariant decides whether to make an employer-facing claim.
 *
 * Two paths, in priority order:
 *
 * 1. Primary: JobMatch already recorded a `direct` CareerFactMatch for
 *    the exact finding this term came from, and the cited CareerFact is
 *    attributed to real work history (Employer/Role/Project — not a
 *    bare CareerProfile-level abstraction). This reuses JobMatch's own
 *    already-vetted judgment (its prompt distinguishes "used" from
 *    "evaluated"; its live evaluation showed it does not invent scale
 *    or claims) rather than re-deriving it from scratch.
 *
 * 2. Fallback, for facts JobMatch never cited (Selection sees the full
 *    corpus — see docs/domain-model.md "ResumeVariant"): an eligible,
 *    attributed CareerFact with the term as an attached canonical
 *    Skill.
 *
 *    IMPORTANT — bounded semantic limitation, not a redefinition of the
 *    CareerFact<->Skill pivot's meaning: Skill attachment proves
 *    topical connection, not proof of hands-on use. A CareerFact
 *    stating "Evaluated migration options including Azure..." could in
 *    principle carry an "Azure" Skill tag without describing real
 *    Azure usage. This fallback does not close that gap — it is
 *    deliberately treated as a residual risk covered by canonical-data
 *    authoring discipline and live/human review (the same trust
 *    boundary every other canonical-data statement already rests on),
 *    not by inventing a technology-evidence ontology or reclassifying
 *    what the Skill pivot universally guarantees. See
 *    docs/resume-variant-contract.md.
 *
 * A bare Skill row with no supporting CareerFact never authorizes a
 * direct claim under either path.
 */
final class DirectEvidenceAuthorization
{
    private const ATTRIBUTED_TYPES = ['employer', 'role', 'project'];

    public static function forFinding(JobMatch $jobMatch, JobAnalysisFinding $finding, string $term): bool
    {
        $hasJobMatchDirect = CareerFactMatch::query()
            ->whereHas('jobMatchFinding', fn ($query) => $query
                ->where('job_match_id', $jobMatch->id)
                ->where('job_analysis_finding_id', $finding->id))
            ->where('relationship', MatchRelationship::Direct)
            ->whereHas('careerFact', fn ($query) => $query->whereIn('attributable_type', self::ATTRIBUTED_TYPES))
            ->exists();

        if ($hasJobMatchDirect) {
            return true;
        }

        return ResumeEligibility::eligibleCareerFactsQuery($jobMatch->careerProfile)
            ->whereIn('attributable_type', self::ATTRIBUTED_TYPES)
            ->whereHas('skills', fn ($query) => $query->whereRaw('LOWER(name) = ?', [mb_strtolower($term)]))
            ->exists();
    }
}
