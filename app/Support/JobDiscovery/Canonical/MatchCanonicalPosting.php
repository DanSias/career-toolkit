<?php

namespace App\Support\JobDiscovery\Canonical;

use App\Support\JobDiscovery\DiscoveredJobCandidate;
use Illuminate\Support\Facades\Log;

/**
 * Deterministically matches one discovered candidate against a
 * resolved board's full canonical listing — normalized title first
 * (exact match, never fuzzy/substring), location as a tiebreaker only
 * when the title alone is ambiguous. Never guesses: zero matches or
 * unresolved ambiguity both return null, leaving the candidate
 * aggregator-only rather than risking attaching the wrong canonical
 * posting. See docs/job-discovery.md "Canonical matching".
 */
final class MatchCanonicalPosting
{
    /**
     * @param  CanonicalJobPosting[]  $board
     */
    public function match(DiscoveredJobCandidate $candidate, array $board): ?CanonicalJobPosting
    {
        $normalizedCandidateTitle = $this->normalizeTitle($candidate->title);

        $titleMatches = array_values(array_filter(
            $board,
            fn (CanonicalJobPosting $posting) => $this->normalizeTitle($posting->title) === $normalizedCandidateTitle,
        ));

        if (count($titleMatches) === 1) {
            return $titleMatches[0];
        }

        if (count($titleMatches) === 0) {
            return null;
        }

        // Ambiguous on title alone — try narrowing by location.
        if ($candidate->location !== null) {
            $locationNarrowed = array_values(array_filter(
                $titleMatches,
                fn (CanonicalJobPosting $posting) => $posting->location !== null
                    && $this->locationsCompatible($candidate->location, $posting->location),
            ));

            if (count($locationNarrowed) === 1) {
                return $locationNarrowed[0];
            }
        }

        Log::info('Canonical match ambiguous — leaving candidate aggregator-only.', [
            'candidate_title' => $candidate->title,
            'candidate_company' => $candidate->company,
            'ambiguous_count' => count($titleMatches),
        ]);

        return null;
    }

    private function normalizeTitle(string $title): string
    {
        $normalized = mb_strtolower(trim($title));
        $normalized = preg_replace('/\s+/u', ' ', $normalized) ?? $normalized;

        return trim($normalized, " \t\n\r\0\x0B.,-");
    }

    private function locationsCompatible(string $a, string $b): bool
    {
        $a = mb_strtolower($a);
        $b = mb_strtolower($b);

        return str_contains($a, $b) || str_contains($b, $a);
    }
}
