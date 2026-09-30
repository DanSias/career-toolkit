<?php

namespace App\Support\JobDiscovery\Canonical;

use App\Support\JobDiscovery\Providers\DiscoveryProviderException;

interface CanonicalRetrievalContract
{
    /**
     * Retrieves every current posting on the given board — matching
     * against a specific discovered candidate is a separate concern
     * (see MatchCanonicalPosting), so this always returns the whole
     * board's listing, never a filtered/matched subset.
     *
     * @return CanonicalJobPosting[]
     *
     * @throws DiscoveryProviderException
     */
    public function retrieveBoard(string $boardIdentifier): array;
}
