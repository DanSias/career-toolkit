<?php

namespace App\Support\JobDiscovery;

use App\Enums\JobDiscoverySource;
use App\Models\CareerProfile;
use App\Models\JobPosting;
use App\Support\JobDiscovery\Canonical\CanonicalJobPosting;
use Illuminate\Support\Facades\DB;

/**
 * Deterministic dedup + create/update — the Phase 1 identity hierarchy
 * (see docs/job-discovery.md "Deduplication strategy"):
 *
 *   1. Exact (discovery_source, discovery_source_id) — the same
 *      provider reporting the same job again.
 *   2. Exact (canonical_source, canonical_source_id), when this
 *      candidate canonically matched — the SAME underlying job
 *      rediscovered via a DIFFERENT discovery provider. Updates the
 *      existing row's mutable/canonical fields but NEVER its
 *      discovery_source/discovery_source_id — see this class's
 *      updateExisting() and App\Enums\JobDiscoverySource's docblock.
 *   3. Normalized application URL, against other DISCOVERED postings
 *      only — see the explicit discovery_source != manual guard on
 *      every query in this class. A manual JobPosting is never
 *      touched by discovery, structurally, not just by convention.
 *
 * Tier 4 (fuzzy company+title+location) is deliberately NOT
 * implemented in Phase 1 — an unresolved cross-provider duplicate is
 * accepted as a known limitation rather than risking an incorrect
 * merge of two distinct jobs. See docs/job-discovery.md.
 */
final class IngestDiscoveredCandidate
{
    public function ingest(
        DiscoveredJobCandidate $candidate,
        ?CanonicalJobPosting $canonicalMatch,
        CareerProfile $careerProfile,
    ): IngestResult {
        return DB::transaction(function () use ($candidate, $canonicalMatch, $careerProfile) {
            $existing = $this->findByDiscoveryIdentity($candidate)
                ?? $this->findByCanonicalIdentity($canonicalMatch)
                ?? $this->findByApplicationUrl($candidate, $canonicalMatch);

            if ($existing !== null) {
                return new IngestResult(
                    jobPosting: $this->updateExisting($existing, $candidate, $canonicalMatch),
                    wasCreated: false,
                    wasCanonicalized: $canonicalMatch !== null,
                );
            }

            return new IngestResult(
                jobPosting: $this->createNew($candidate, $canonicalMatch, $careerProfile),
                wasCreated: true,
                wasCanonicalized: $canonicalMatch !== null,
            );
        });
    }

    private function findByDiscoveryIdentity(DiscoveredJobCandidate $candidate): ?JobPosting
    {
        return JobPosting::query()
            ->where('discovery_source', $candidate->source)
            ->where('discovery_source_id', $candidate->sourceJobId)
            ->first();
    }

    private function findByCanonicalIdentity(?CanonicalJobPosting $canonicalMatch): ?JobPosting
    {
        if ($canonicalMatch === null) {
            return null;
        }

        return JobPosting::query()
            ->where('discovery_source', '!=', JobDiscoverySource::Manual->value)
            ->where('canonical_source', $canonicalMatch->canonicalSource)
            ->where('canonical_source_id', $canonicalMatch->canonicalSourceId)
            ->first();
    }

    private function findByApplicationUrl(DiscoveredJobCandidate $candidate, ?CanonicalJobPosting $canonicalMatch): ?JobPosting
    {
        $url = ($canonicalMatch !== null ? $canonicalMatch->applicationUrl : null) ?? $candidate->applicationUrl;

        if ($url === null) {
            return null;
        }

        $normalized = $this->normalizeUrl($url);

        return JobPosting::query()
            ->where('discovery_source', '!=', JobDiscoverySource::Manual->value)
            ->whereNotNull('source_url')
            ->get(['id', 'source_url'])
            ->first(fn (JobPosting $job) => $this->normalizeUrl($job->source_url) === $normalized);
    }

    private function createNew(DiscoveredJobCandidate $candidate, ?CanonicalJobPosting $canonicalMatch, CareerProfile $careerProfile): JobPosting
    {
        $c = $this->canonicalFields($canonicalMatch);

        return JobPosting::create([
            'career_profile_id' => $careerProfile->id,
            'company' => $candidate->company,
            'title' => $c['title'] ?? $candidate->title,
            'description' => $c['description'] ?? $candidate->description ?? '',
            'location' => $c['location'] ?? $candidate->location,
            'source_url' => $c['applicationUrl'] ?? $candidate->applicationUrl,
            'discovery_source' => $candidate->source,
            'discovery_source_id' => $candidate->sourceJobId,
            'canonical_source' => $c['canonicalSource'] ?? null,
            'canonical_source_id' => $c['canonicalSourceId'] ?? null,
            'remote_status' => $c['remoteStatus'] ?? $candidate->remoteStatus,
            'employment_type' => $c['employmentType'] ?? $candidate->employmentType,
            'compensation_min' => $c['compensationMin'] ?? $candidate->compensationMin,
            'compensation_max' => $c['compensationMax'] ?? $candidate->compensationMax,
            'compensation_currency' => $c['compensationCurrency'] ?? $candidate->compensationCurrency,
            'compensation_interval' => $c['compensationInterval'] ?? $candidate->compensationInterval,
            'posted_at' => $candidate->postedAt,
            'source_updated_at' => $c['sourceUpdatedAt'] ?? $candidate->sourceUpdatedAt,
            'discovered_at' => $candidate->discoveredAt,
            'last_checked_at' => now(),
            'discovery_metadata' => $this->mergedMetadata($candidate, $canonicalMatch),
        ]);
    }

    /**
     * Mutable fields only. discovery_source/discovery_source_id/
     * discovered_at are write-once and never appear here — see this
     * class's own docblock.
     */
    private function updateExisting(JobPosting $existing, DiscoveredJobCandidate $candidate, ?CanonicalJobPosting $canonicalMatch): JobPosting
    {
        $c = $this->canonicalFields($canonicalMatch);

        $existing->update([
            'title' => $c['title'] ?? $candidate->title,
            'description' => $c['description'] ?? $candidate->description ?? $existing->description,
            'location' => $c['location'] ?? $candidate->location,
            'source_url' => $c['applicationUrl'] ?? $candidate->applicationUrl ?? $existing->source_url,
            // Never unset once resolved, even on a later rediscovery
            // where canonical matching happened not to run/succeed.
            'canonical_source' => $c['canonicalSource'] ?? $existing->canonical_source,
            'canonical_source_id' => $c['canonicalSourceId'] ?? $existing->canonical_source_id,
            'remote_status' => $c['remoteStatus'] ?? $candidate->remoteStatus ?? $existing->remote_status,
            'employment_type' => $c['employmentType'] ?? $candidate->employmentType ?? $existing->employment_type,
            'compensation_min' => $c['compensationMin'] ?? $candidate->compensationMin ?? $existing->compensation_min,
            'compensation_max' => $c['compensationMax'] ?? $candidate->compensationMax ?? $existing->compensation_max,
            'compensation_currency' => $c['compensationCurrency'] ?? $candidate->compensationCurrency ?? $existing->compensation_currency,
            'compensation_interval' => $c['compensationInterval'] ?? $candidate->compensationInterval ?? $existing->compensation_interval,
            'source_updated_at' => $c['sourceUpdatedAt'] ?? $candidate->sourceUpdatedAt ?? $existing->source_updated_at,
            'last_checked_at' => now(),
            'discovery_metadata' => $this->mergedMetadata($candidate, $canonicalMatch, $existing->discovery_metadata ?? []),
        ]);

        return $existing;
    }

    /**
     * Flattens a possibly-null CanonicalJobPosting into a plain array
     * so every field access below is ordinary array-key ??, never a
     * nullsafe-property-access-on-the-left-of-?? chain repeated a
     * dozen times.
     *
     * @return array<string, mixed>
     */
    private function canonicalFields(?CanonicalJobPosting $canonicalMatch): array
    {
        if ($canonicalMatch === null) {
            return [];
        }

        return [
            'title' => $canonicalMatch->title,
            'description' => $canonicalMatch->description,
            'location' => $canonicalMatch->location,
            'applicationUrl' => $canonicalMatch->applicationUrl,
            'canonicalSource' => $canonicalMatch->canonicalSource,
            'canonicalSourceId' => $canonicalMatch->canonicalSourceId,
            'remoteStatus' => $canonicalMatch->remoteStatus,
            'employmentType' => $canonicalMatch->employmentType,
            'compensationMin' => $canonicalMatch->compensationMin,
            'compensationMax' => $canonicalMatch->compensationMax,
            'compensationCurrency' => $canonicalMatch->compensationCurrency,
            'compensationInterval' => $canonicalMatch->compensationInterval,
            'sourceUpdatedAt' => $canonicalMatch->sourceUpdatedAt,
        ];
    }

    /**
     * @param  array<string, mixed>  $previous
     * @return array<string, mixed>
     */
    private function mergedMetadata(DiscoveredJobCandidate $candidate, ?CanonicalJobPosting $canonicalMatch, array $previous = []): array
    {
        return array_filter([
            ...$previous,
            'discovery' => $candidate->sourceMetadata,
            'canonical' => $canonicalMatch?->sourceMetadata,
        ], fn ($value) => $value !== null && $value !== []);
    }

    private function normalizeUrl(string $url): string
    {
        $parts = parse_url(mb_strtolower(trim($url)));

        if ($parts === false) {
            return mb_strtolower(trim($url));
        }

        $path = rtrim($parts['path'] ?? '', '/');

        return ($parts['host'] ?? '').$path;
    }
}
