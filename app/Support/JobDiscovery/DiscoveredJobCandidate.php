<?php

namespace App\Support\JobDiscovery;

use App\Enums\DescriptionCompleteness;
use App\Enums\JobDiscoverySource;
use App\Enums\JobRemoteStatus;
use Carbon\CarbonImmutable;

/**
 * The normalized shape every discovery provider maps its raw response
 * into — so nothing downstream (filtering, canonical matching,
 * ingestion) ever touches a provider's own payload shape directly.
 * Every field except source/sourceJobId/company/title/
 * descriptionCompleteness/discoveredAt is legitimately nullable: not
 * every provider populates every field, and a missing one is never a
 * reason to reject a candidate on its own (see App\Support\
 * JobDiscovery\FilterDiscoveredCandidates).
 *
 * descriptionCompleteness has no default and every provider must pass
 * it explicitly — a provider can never silently populate `description`
 * without consciously declaring whether it's Complete, Preview, or
 * Unknown. See App\Enums\DescriptionCompleteness.
 *
 * Immutable/readonly on purpose — a candidate is a fact about what a
 * provider returned at discovery time, never mutated afterward. See
 * docs/job-discovery.md "Normalized discovery contract".
 */
final readonly class DiscoveredJobCandidate
{
    /**
     * @param  array<string, mixed>  $sourceMetadata  Small, structured,
     *                                                source-specific extras worth keeping (never a raw full
     *                                                provider payload dump) — see this property's own callers for
     *                                                what each provider puts here.
     */
    public function __construct(
        public JobDiscoverySource $source,
        public string $sourceJobId,
        public string $company,
        public string $title,
        public ?string $description,
        public DescriptionCompleteness $descriptionCompleteness,
        public ?string $location,
        public ?JobRemoteStatus $remoteStatus,
        public ?string $employmentType,
        public ?int $compensationMin,
        public ?int $compensationMax,
        public ?string $compensationCurrency,
        public ?string $compensationInterval,
        public ?string $applicationUrl,
        public ?CarbonImmutable $postedAt,
        public ?CarbonImmutable $sourceUpdatedAt,
        public CarbonImmutable $discoveredAt,
        public array $sourceMetadata = [],
    ) {}
}
