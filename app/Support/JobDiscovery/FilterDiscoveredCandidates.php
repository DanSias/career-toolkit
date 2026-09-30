<?php

namespace App\Support\JobDiscovery;

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
        if ($criteria->keywords !== [] && ! $this->matchesAnyKeyword($haystack, $criteria->keywords)) {
            return false;
        }

        if ($criteria->excludedKeywords !== [] && $this->matchesAnyKeyword($haystack, $criteria->excludedKeywords)) {
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
            if (str_contains($haystack, mb_strtolower($keyword))) {
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
            if (str_contains($location, mb_strtolower($allowed))) {
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
