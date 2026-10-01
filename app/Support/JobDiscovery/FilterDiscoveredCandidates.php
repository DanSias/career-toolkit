<?php

namespace App\Support\JobDiscovery;

use App\Enums\JobDiscoverySource;
use App\Enums\JobRemoteStatus;

/**
 * Deterministic discovery filtering — never fit scoring. Every check
 * here is a plain, explainable yes/no; nothing ranks or scores a
 * candidate against another. Conservative by design: a criterion only
 * rejects a candidate when the provider actually gave a value that
 * contradicts it — missing metadata is never itself a reason to
 * reject, per docs/job-discovery.md "Filtering".
 */
final class FilterDiscoveredCandidates
{
    public function accepts(DiscoveredJobCandidate $candidate, DiscoverySearchCriteria $criteria): bool
    {
        $haystack = mb_strtolower($candidate->title.' '.($candidate->description ?? ''));

        // Empty keywords = no positive filter = accept everything;
        // empty excludedKeywords = nothing to exclude — these are
        // deliberately NOT the same "empty means accept" shortcut, so
        // each uses its own explicit check rather than sharing one
        // matchesAnyKeyword() semantics that only fits one direction.
        if ($criteria->keywords !== [] && ! $this->matchesAnyKeyword($candidate->title, $criteria->keywords)) {
            return false;
        }

        if ($criteria->excludedKeywords !== [] && $this->matchesAnyKeyword($haystack, $criteria->excludedKeywords)) {
            return false;
        }

        // Title-only role-identity exclusions (SRE / Site Reliability /
        // primarily DevOps). Checked against the TITLE, never the
        // description: infrastructure terminology in a job description
        // is common in legitimate product-engineering roles, while a
        // title naming that role is a reliable identity signal.
        if ($criteria->excludedTitleKeywords !== [] && $this->matchesAnyKeyword($candidate->title, $criteria->excludedTitleKeywords)) {
            return false;
        }

        if (! $this->acceptsRemotePreference($candidate, $criteria)) {
            return false;
        }

        if (! $this->acceptsEmploymentType($candidate, $criteria)) {
            return false;
        }

        return true;
    }

    /**
     * Both call sites in accepts() already guard the empty-list case
     * explicitly before calling this — see accepts()'s own comment —
     * so this only ever runs with a genuinely non-empty list.
     *
     * @param  string[]  $keywords
     */
    private function matchesAnyKeyword(string $haystack, array $keywords): bool
    {
        foreach ($keywords as $keyword) {
            $words = preg_split('/\\s+/u', trim($keyword)) ?: [];
            if ($words === [] || $words === ['']) {
                continue;
            }
            $phrase = implode('\\s+', array_map(fn (string $word) => preg_quote($word, '~'), $words));
            if (preg_match('~(?<![\\p{L}\\p{N}_])'.$phrase.'(?![\\p{L}\\p{N}_])~iu', $haystack) === 1) {
                return true;
            }
        }

        return false;
    }

    private function acceptsRemotePreference(DiscoveredJobCandidate $candidate, DiscoverySearchCriteria $criteria): bool
    {
        if ($criteria->remotePreference !== 'remote_us') {
            return true;
        }

        // An explicit, contradicting signal is the only thing that
        // rejects here — Onsite/Hybrid when remote_us is required.
        if ($candidate->remoteStatus === JobRemoteStatus::Onsite || $candidate->remoteStatus === JobRemoteStatus::Hybrid) {
            return false;
        }

        // Himalayas supplies explicit eligible-country restrictions. Remote
        // describes the workplace, not permission to work from any country.
        if ($candidate->source === JobDiscoverySource::Himalayas) {
            $restrictions = $candidate->sourceMetadata['locationRestrictions'] ?? null;
            if (is_array($restrictions)) {
                $countries = array_values(array_filter($restrictions, fn ($country) => is_string($country) && trim($country) !== ''));
                if ($countries !== [] && array_intersect(array_map(fn (string $country) => mb_strtolower(trim($country)), $countries), ['united states', 'united states of america', 'us', 'usa', 'worldwide', 'anywhere', 'global']) === []) {
                    return false;
                }
            }
        }

        if ($candidate->remoteStatus === JobRemoteStatus::Remote) {
            return true;
        }

        // Unknown remote status: fall back to location text rather
        // than rejecting outright — conservative, per this class's
        // own docblock.
        if ($candidate->location === null) {
            return true;
        }

        $location = mb_strtolower($candidate->location);

        foreach ($criteria->allowedLocations as $allowed) {
            if ($this->matchesAnyKeyword($location, [$allowed])) {
                return true;
            }
        }

        // A location was given and none of it matched an allowed
        // one — this is the one case an unset remote_status still
        // rejects, since the candidate positively named a location
        // that isn't in the allowed set (e.g. "Berlin, Germany").
        return false;
    }

    private function acceptsEmploymentType(DiscoveredJobCandidate $candidate, DiscoverySearchCriteria $criteria): bool
    {
        if ($criteria->employmentType === null || $candidate->employmentType === null) {
            return true;
        }

        return mb_strtolower($candidate->employmentType) === mb_strtolower($criteria->employmentType);
    }
}
