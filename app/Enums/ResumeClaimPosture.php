<?php

namespace App\Enums;

/**
 * What kind of claim a single target-term usage makes in generated
 * resume content — deliberately a distinct type from
 * App\Enums\MatchRelationship, not inherited from it. JobMatch's
 * relationship answers "how does this evidence relate to this
 * finding," a diagnostic classification computed once, independent of
 * any specific sentence. ResumeClaimPosture answers "how is *this
 * specific resume claim* allowed to position this term" — a
 * communication decision made at generation time. The two may
 * legitimately diverge (a `transferable` JobMatch citation might back
 * either a `Qualified` resume claim or a plain `Capability` bullet that
 * never names the target term at all). See docs/resume-variant-contract.md.
 *
 * Posture belongs to a specific target-term usage
 * (ResumeVariantTargetTermUsage), never to an entire bullet and never
 * to every citation on it — a bullet may combine several `Direct`/
 * `Capability` usages with at most one `Qualified` usage.
 */
enum ResumeClaimPosture: string
{
    /**
     * The resume states the named technology/capability as the
     * candidate's own experience. Requires real, attributed evidence —
     * see App\Support\ResumeVariant\DirectEvidenceAuthorization.
     */
    case Direct = 'direct';

    /**
     * The resume makes an explicit, structurally-controlled comparison:
     * real evidence for technology X, positioned as comparable/
     * transferable to requested technology Y. Y may appear in the
     * rendered output only through the qualified-clause mechanism —
     * never in free-generated prose.
     */
    case Qualified = 'qualified';

    /**
     * The underlying capability/pattern is represented; the requested
     * technology's name does not appear at all. Persisted explicitly
     * (never inferred from the absence of a row) so the ATS-terminology
     * review can distinguish "deliberately represented at the
     * capability level" from "not addressed."
     */
    case Capability = 'capability';
}
