<?php

namespace App\Support\JobDiscovery;

use App\Enums\DescriptionCompleteness;
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
            $discovery = $this->findByDiscoveryIdentity($candidate);
            $canonical = $this->findByCanonicalIdentity($canonicalMatch);
            if ($discovery !== null && $canonical !== null && $discovery->id !== $canonical->id) {
                throw new DiscoveryIngestionException('Discovery and canonical identities belong to different postings; candidate skipped.');
            }
            if ($discovery !== null && $this->canonicalConflicts($discovery, $canonicalMatch)) {
                // A reused aggregator ID cannot represent a new posting without
                // colliding with the original discovery identity. Never overwrite it.
                throw new DiscoveryIngestionException('Discovery identity was reused for a different canonical posting; candidate skipped.');
            }
            $existing = $discovery ?? $canonical ?? $this->findByApplicationUrl($candidate, $canonicalMatch);

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

        $matches = JobPosting::query()
            ->where('discovery_source', '!=', JobDiscoverySource::Manual->value)
            ->whereNotNull('source_url')
            ->get()
            ->filter(fn (JobPosting $job) => $this->normalizeUrl($job->source_url) === $normalized)
            // A new canonical posting sharing an old URL is a repost, not an
            // update. Its new discovery identity permits a separate row.
            ->reject(fn (JobPosting $job) => $this->canonicalConflicts($job, $canonicalMatch));

        if ($matches->count() > 1) {
            throw new DiscoveryIngestionException('Application URL matches multiple postings; candidate skipped.');
        }

        return $matches->first();
    }

    private function canonicalConflicts(JobPosting $existing, ?CanonicalJobPosting $canonical): bool
    {
        return $canonical !== null && $existing->canonical_source !== null
            && ($existing->canonical_source !== $canonical->canonicalSource
                || $existing->canonical_source_id !== $canonical->canonicalSourceId);
    }

    private function createNew(DiscoveredJobCandidate $candidate, ?CanonicalJobPosting $canonicalMatch, CareerProfile $careerProfile): JobPosting
    {
        $c = $this->canonicalFields($canonicalMatch);

        return JobPosting::create([
            'career_profile_id' => $careerProfile->id,
            'company' => $candidate->company,
            'title' => $c['title'] ?? $candidate->title,
            'description' => $c['description'] ?? $candidate->description ?? '',
            'description_completeness' => $this->resolveDescriptionCompleteness($candidate, $canonicalMatch),
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
        $updates = [
            'last_checked_at' => now(),
            'discovery_metadata' => $this->mergedMetadata($candidate, $canonicalMatch, $existing),
        ];

        // Failed/absent enrichment must not downgrade authoritative content.
        // A successful same-identity refresh may update it again later.
        if ($existing->canonical_source === null || $canonicalMatch !== null) {
            $fields = [
                'title' => 'title', 'description' => 'description', 'location' => 'location',
                'source_url' => 'applicationUrl', 'remote_status' => 'remoteStatus',
                'employment_type' => 'employmentType', 'compensation_min' => 'compensationMin',
                'compensation_max' => 'compensationMax', 'compensation_currency' => 'compensationCurrency',
                'compensation_interval' => 'compensationInterval', 'source_updated_at' => 'sourceUpdatedAt',
            ];
            foreach ($fields as $column => $property) {
                $value = $canonicalMatch?->{$property};
                if ($value === null && $existing->canonical_source === null) {
                    $value = $candidate->{$property};
                }
                if ($value !== null) {
                    $updates[$column] = $value;
                }
            }
            // Completeness must describe whichever description text
            // just won above — not merely whether a canonical match
            // exists (its own description can itself be null; see
            // resolveDescriptionCompleteness()). Only set when
            // 'description' was actually updated this pass, so an
            // unchanged stored description never gets its
            // completeness flipped for no reason.
            if (array_key_exists('description', $updates)) {
                $updates['description_completeness'] = $this->resolveDescriptionCompleteness($candidate, $canonicalMatch);
            }
            if ($canonicalMatch !== null) {
                $updates['canonical_source'] = $canonicalMatch->canonicalSource;
                $updates['canonical_source_id'] = $canonicalMatch->canonicalSourceId;
            }
        }
        $existing->update($updates);

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
     * Describes the description text ACTUALLY STORED — the same
     * precedence as the 'description' column itself
     * (`$c['description'] ?? $candidate->description`), never merely
     * "a canonical match exists." A canonical match whose own
     * description is null never wins the text, so it must never win
     * the completeness tag either.
     */
    private function resolveDescriptionCompleteness(DiscoveredJobCandidate $candidate, ?CanonicalJobPosting $canonicalMatch): DescriptionCompleteness
    {
        if ($canonicalMatch !== null && $canonicalMatch->description !== null) {
            return $canonicalMatch->descriptionCompleteness;
        }

        return $candidate->descriptionCompleteness;
    }

    /** @return array<string, mixed> */
    private function mergedMetadata(DiscoveredJobCandidate $candidate, ?CanonicalJobPosting $canonicalMatch, ?JobPosting $existing = null): array
    {
        $metadata = $existing->discovery_metadata ?? [];
        if ($existing === null || ($existing->discovery_source === $candidate->source
            && $existing->discovery_source_id === $candidate->sourceJobId)) {
            $metadata['discovery'] = $candidate->sourceMetadata;
        }
        if ($canonicalMatch !== null && $canonicalMatch->sourceMetadata !== []) {
            $metadata['canonical'] = $canonicalMatch->sourceMetadata;
        }

        return $metadata;
    }

    private function normalizeUrl(string $url): string
    {
        $url = trim($url);
        $parts = parse_url($url);
        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            return $url;
        }

        $scheme = strtolower($parts['scheme']);
        $host = strtolower($parts['host']);
        $port = $parts['port'] ?? null;
        $authority = isset($parts['user']) ? $parts['user'].(isset($parts['pass']) ? ':'.$parts['pass'] : '').'@' : '';
        $authority .= $host;
        if ($port !== null && ! ($scheme === 'https' && $port === 443) && ! ($scheme === 'http' && $port === 80)) {
            $authority .= ':'.$port;
        }

        // Preserve case, query order/values and fragments: any may identify
        // a distinct job. No unverified "tracking" parameter removal.
        return $scheme.'://'.$authority.rtrim($parts['path'] ?? '', '/')
            .(isset($parts['query']) ? '?'.$parts['query'] : '')
            .(isset($parts['fragment']) ? '#'.$parts['fragment'] : '');
    }
}
