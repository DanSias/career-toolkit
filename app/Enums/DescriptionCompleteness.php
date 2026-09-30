<?php

namespace App\Enums;

/**
 * Provider/source-level semantics for whether a description is the
 * substantially complete posting, or only a teaser/excerpt — never a
 * per-posting quality score and never inferred from description
 * length at runtime. Every DiscoveredJobCandidate and
 * CanonicalJobPosting must declare this explicitly (no default), so a
 * provider can never silently populate `description` without
 * consciously deciding which of these it actually supplies — see
 * App\Support\JobDiscovery\DiscoveredJobCandidate and
 * App\Support\JobDiscovery\Canonical\CanonicalJobPosting. Introduced
 * after the Adzuna investigation found a ~500-character aggregator
 * snippet being stored and treated identically to a genuinely
 * complete posting, with nothing anywhere recording the difference.
 */
enum DescriptionCompleteness: string
{
    /**
     * The provider/source is known (by verified behavior, not
     * per-posting inference) to supply the substantially complete
     * posting description.
     */
    case Complete = 'complete';

    /**
     * The provider/source is known to supply only a teaser/excerpt/
     * truncated description.
     */
    case Preview = 'preview';

    /**
     * Completeness cannot be established for this source.
     */
    case Unknown = 'unknown';
}
