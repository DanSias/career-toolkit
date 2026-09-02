<?php

namespace App\Enums;

/**
 * What KIND of support a single CareerFact or Education reference gives
 * to a JobAnalysisFinding — deliberately categorical, not an ordinal
 * "good/better/best" ladder (see docs/job-match-contract.md for why:
 * an ordinal scale invites the same miscalibration JobAnalysis's
 * `basis` field showed in its first live evaluation, where the model
 * defaulted almost entirely to one "safe" value).
 *
 * Shared by both CareerFactMatch and EducationMatch — the meaning of
 * "what kind of relationship is this" doesn't change based on which
 * canonical entity is being cited.
 */
enum MatchRelationship: string
{
    /**
     * The evidence demonstrates substantially the same capability,
     * technology, domain, scope, or experience the finding asks for.
     */
    case Direct = 'direct';

    /**
     * The evidence demonstrates a closely analogous capability in a
     * different technology/domain/context — legitimately relevant, but
     * not the same experience.
     */
    case Transferable = 'transferable';

    /**
     * The evidence strengthens the candidate's overall case or supplies
     * useful background, without independently demonstrating the
     * requested capability.
     */
    case Contextual = 'contextual';
}
