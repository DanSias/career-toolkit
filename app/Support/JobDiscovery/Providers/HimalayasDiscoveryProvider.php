<?php

namespace App\Support\JobDiscovery\Providers;

use App\Enums\DescriptionCompleteness;
use App\Enums\JobDiscoverySource;
use App\Enums\JobRemoteStatus;
use App\Support\JobDiscovery\DiscoveredJobCandidate;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Himalayas' public jobs feed (himalayas.app/jobs/api) — free, no
 * authentication, live-verified during the Automatic Job Discovery
 * investigation and again directly against this exact adapter. Every
 * job on Himalayas is remote by construction (it is a remote-jobs-only
 * board), so remoteStatus is always Remote here — not a per-job
 * inference, a platform-level fact.
 *
 * No confirmed server-side keyword/category search exists in this
 * feed (only limit/offset/cursor pagination) — see this class's
 * retrieve() docblock for why this provider pulls a single bounded
 * recent batch and leaves all keyword/seniority/exclusion filtering to
 * App\Support\JobDiscovery\FilterDiscoveredCandidates rather than a
 * provider-specific query param this codebase couldn't verify.
 *
 * The feed silently caps `limit` at 20 regardless of what's
 * requested — confirmed directly against the real live endpoint
 * during Discovery Phase 1's Checkpoint A validation (requesting 200
 * still returns exactly 20, with `limit: 20` echoed back in the
 * response). One page (20 candidates) per run is therefore the real
 * per-run ceiling for this provider in Phase 1 — real cursor-based
 * pagination across multiple pages per run is a Phase 2+ enhancement,
 * not implemented here; daily reruns still accumulate fresh coverage
 * over time via the same dedup path every other rediscovery uses.
 */
final class HimalayasDiscoveryProvider implements DiscoveryProviderContract
{
    private const TIMEOUT_SECONDS = 30;

    private const RETRY_TIMES = 3;

    private const RETRY_DELAY_MILLISECONDS = 1000;

    private const RETRYABLE_STATUSES = [408, 429, 500, 502, 503, 504];

    public function __construct(
        // Requested as an upper bound — the live feed currently caps
        // the real response at 20 regardless of this value. See this
        // class's own docblock.
        private readonly int $limit = 200,
    ) {}

    /**
     * @return DiscoveredJobCandidate[]
     */
    public function retrieve(): array
    {
        $baseUrl = (string) config('services.himalayas.base_url');

        try {
            $response = Http::timeout(self::TIMEOUT_SECONDS)
                ->retry(
                    self::RETRY_TIMES,
                    self::RETRY_DELAY_MILLISECONDS,
                    fn (Throwable $e) => $this->isRetryable($e),
                    throw: false,
                )
                ->get($baseUrl, ['limit' => $this->limit, 'offset' => 0]);
        } catch (ConnectionException $e) {
            Log::warning('Himalayas discovery: connection failure.');

            throw new DiscoveryProviderException('Himalayas request failed: connection error.', previous: $e);
        }

        if ($response->failed()) {
            Log::warning('Himalayas discovery: request failed.', ['status' => $response->status()]);

            throw new DiscoveryProviderException("Himalayas request failed with HTTP status {$response->status()}.");
        }

        $jobs = $response->json('jobs');

        if (! is_array($jobs)) {
            throw new DiscoveryProviderException('Himalayas response did not contain a jobs array — response shape may have changed.');
        }

        foreach ($jobs as $job) {
            if (! is_array($job)) {
                throw new DiscoveryProviderException('Provider response contained a malformed job entry.');
            }
        }

        try {
            return array_values(array_filter(array_map(
                fn (array $job) => $this->normalize($job),
                $jobs,
            )));
        } catch (\TypeError|\ValueError $e) {
            throw new DiscoveryProviderException('Provider response contained malformed job fields.', previous: $e);
        }
    }

    /**
     * @param  array<string, mixed>  $job
     */
    private function normalize(array $job): ?DiscoveredJobCandidate
    {
        // guid/applicationLink are the only stable per-posting
        // identifiers this feed exposes (no numeric id field) —
        // verified directly against a real live response.
        $sourceJobId = $job['guid'] ?? $job['applicationLink'] ?? null;
        $title = $job['title'] ?? null;
        $company = $job['companyName'] ?? null;

        if (! is_string($sourceJobId) || $sourceJobId === '' || ! is_string($title) || ! is_string($company)) {
            return null;
        }

        return new DiscoveredJobCandidate(
            source: JobDiscoverySource::Himalayas,
            sourceJobId: $sourceJobId,
            company: $company,
            title: $title,
            description: is_string($job['description'] ?? null) ? $job['description'] : null,
            // Himalayas itself models snippet-vs-complete as two
            // distinct fields (a separate short `excerpt` alongside
            // `description`) — its `description` is the genuinely
            // complete posting, confirmed against real live samples
            // (4,300-6,500+ chars, no truncation) during the
            // Automatic Job Discovery investigation.
            descriptionCompleteness: DescriptionCompleteness::Complete,
            location: $this->normalizeLocation($job['locationRestrictions'] ?? null),
            remoteStatus: JobRemoteStatus::Remote,
            employmentType: is_string($job['employmentType'] ?? null) ? $job['employmentType'] : null,
            compensationMin: is_int($job['minSalary'] ?? null) ? $job['minSalary'] : null,
            compensationMax: is_int($job['maxSalary'] ?? null) ? $job['maxSalary'] : null,
            compensationCurrency: is_string($job['currency'] ?? null) ? $job['currency'] : null,
            compensationInterval: is_string($job['salaryPeriod'] ?? null) ? $job['salaryPeriod'] : null,
            applicationUrl: is_string($job['applicationLink'] ?? null) ? $job['applicationLink'] : null,
            postedAt: $this->parseUnixTimestamp($job['pubDate'] ?? null),
            sourceUpdatedAt: null,
            discoveredAt: now(),
            sourceMetadata: array_filter([
                'expiryDate' => $job['expiryDate'] ?? null,
                'locationRestrictions' => $job['locationRestrictions'] ?? null,
                'seniority' => $job['seniority'] ?? null,
                'categories' => $job['categories'] ?? null,
                'companySlug' => $job['companySlug'] ?? null,
            ], fn ($value) => $value !== null),
        );
    }

    /**
     * @param  mixed  $restrictions
     */
    private function normalizeLocation($restrictions): ?string
    {
        if (! is_array($restrictions) || $restrictions === []) {
            return null;
        }

        return implode(', ', array_filter($restrictions, 'is_string'));
    }

    private function parseUnixTimestamp(mixed $timestamp): ?CarbonImmutable
    {
        if (! is_int($timestamp)) {
            return null;
        }

        return CarbonImmutable::createFromTimestamp($timestamp);
    }

    private function isRetryable(Throwable $exception): bool
    {
        if ($exception instanceof ConnectionException) {
            return true;
        }

        return $exception instanceof RequestException
            && in_array($exception->response->status(), self::RETRYABLE_STATUSES, true);
    }
}
