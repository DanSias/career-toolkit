<?php

namespace App\Support\JobDiscovery\Providers;

use App\Support\JobDiscovery\DiscoveredJobCandidate;

interface DiscoveryProviderContract
{
    /**
     * Retrieves and normalizes candidates from this provider. Throws
     * DiscoveryProviderException on any provider-level failure
     * (network, malformed response, missing config) — never returns a
     * partial/empty array to signal failure, so a genuine "no jobs
     * matched" response is never confused with an error.
     *
     * @return DiscoveredJobCandidate[]
     *
     * @throws DiscoveryProviderException
     */
    public function retrieve(): array;
}
