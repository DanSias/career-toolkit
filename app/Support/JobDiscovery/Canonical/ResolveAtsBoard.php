<?php

namespace App\Support\JobDiscovery\Canonical;

use App\Models\KnownAtsBoard;

/**
 * Company name → known ATS board lookup. A miss is never an error —
 * canonical enrichment is an enhancement, not an ingestion
 * prerequisite (see App\Support\JobDiscovery\IngestDiscoveredCandidate
 * and docs/job-discovery.md "Canonical resolution must not block
 * ingestion").
 */
final class ResolveAtsBoard
{
    public function resolve(string $company): ?KnownAtsBoard
    {
        return KnownAtsBoard::query()
            ->where('company_name', NormalizeCompanyName::normalize($company))
            ->first();
    }
}
