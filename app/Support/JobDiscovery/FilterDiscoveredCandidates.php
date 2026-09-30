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

        if (! $this->matchesAnyKeyword($haystack, $criteria->keywords)) {
            return false;
        }

        if ($this->matchesAnyKeyword($haystack, $criteria->excludedKeywords)) {
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
     * @param  string[]  $keywords
     */
    private function matchesAnyKeyword(string $haystack, array $keywords): bool
    {
        if ($keywords === []) {
            return true;
        }

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
