<?php

namespace App\Support\JobDiscovery\Canonical;

use App\Enums\DescriptionCompleteness;
use App\Enums\JobCanonicalSource;
use App\Enums\JobRemoteStatus;
use Carbon\CarbonImmutable;

/**
 * One posting as returned by a canonical ATS adapter (Greenhouse/
 * Lever/Ashby) — deliberately a separate shape from
 * App\Support\JobDiscovery\DiscoveredJobCandidate (which is keyed by
 * JobDiscoverySource, not JobCanonicalSource): a discovery candidate
 * and its canonical match are two different facts about two different
 * kinds of source, matched by App\Support\JobDiscovery\Canonical\
 * MatchCanonicalPosting, never conflated into one type. See
 * docs/job-discovery.md "Discovery identity vs. canonical identity".
 *
 * descriptionCompleteness has no default and every canonical adapter
 * must pass it explicitly — kept symmetrical with
 * DiscoveredJobCandidate's own field; see App\Enums\
 * DescriptionCompleteness.
 */
final readonly class CanonicalJobPosting
{
    /**
     * @param  array<string, mixed>  $sourceMetadata
     */
    public function __construct(
        public JobCanonicalSource $canonicalSource,
        public string $canonicalSourceId,
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
        public ?CarbonImmutable $sourceUpdatedAt,
        public array $sourceMetadata = [],
    ) {}
}
