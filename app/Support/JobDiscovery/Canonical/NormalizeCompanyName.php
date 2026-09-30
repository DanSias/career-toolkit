<?php

namespace App\Support\JobDiscovery\Canonical;

/**
 * Simple, deterministic company-name normalization — lowercase, trim,
 * collapse internal whitespace, strip trailing punctuation. No
 * fuzzy/AI matching and no corporate-suffix stripping ("Inc"/"LLC"/
 * "Corp") — see docs/job-discovery.md "Company name normalization"
 * for why: stripping suffixes risks silently colliding two distinct
 * companies that happen to share a base name, and no evidence from
 * this phase showed that was actually needed. If a discovered
 * candidate's company name doesn't normalize to exactly the same
 * string stored on App\Models\KnownAtsBoard, resolution fails
 * gracefully — see App\Support\JobDiscovery\Canonical\ResolveAtsBoard.
 */
final class NormalizeCompanyName
{
    public static function normalize(string $company): string
    {
        $normalized = mb_strtolower(trim($company));
        $normalized = preg_replace('/\s+/u', ' ', $normalized) ?? $normalized;

        return rtrim($normalized, '.,');
    }
}
