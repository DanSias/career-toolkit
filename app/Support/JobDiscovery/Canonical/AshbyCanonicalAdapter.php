<?php

namespace App\Support\JobDiscovery\Canonical;

use App\Enums\JobCanonicalSource;
use App\Enums\JobRemoteStatus;
use App\Support\JobDiscovery\Providers\DiscoveryProviderException;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Ashby's public, officially-documented job-board API
 * (api.ashbyhq.com/posting-api) — free, no authentication,
 * live-verified during the Automatic Job Discovery investigation and
 * again directly against this exact adapter — the only one of the
 * three ATSs with real public documentation.
 *
 * `compensation` (with ?includeCompensation=true) is a free-text
 * summary ("€110K – €185K • Offers Equity • Offers Bonus"), not clean
 * numeric fields — confirmed directly against a real response.
 * Parsing that text into compensationMin/Max risks fabricating
 * precision Ashby never actually provided, so this adapter leaves
 * those fields null and keeps the raw summary in sourceMetadata
 * instead — a deliberate, evidence-based simplification; see
 * docs/job-discovery.md "Canonical enrichment — Ashby".
 */
final class AshbyCanonicalAdapter implements CanonicalRetrievalContract
{
    private const TIMEOUT_SECONDS = 30;

    private const RETRY_TIMES = 3;

    private const RETRY_DELAY_MILLISECONDS = 1000;

    private const RETRYABLE_STATUSES = [408, 429, 500, 502, 503, 504];

    public function retrieveBoard(string $boardIdentifier): array
    {
        $endpoint = "https://api.ashbyhq.com/posting-api/job-board/{$boardIdentifier}";

        try {
            $response = Http::timeout(self::TIMEOUT_SECONDS)
                ->retry(
                    self::RETRY_TIMES,
                    self::RETRY_DELAY_MILLISECONDS,
                    fn (Throwable $e) => $this->isRetryable($e),
                    throw: false,
                )
                ->get($endpoint, ['includeCompensation' => 'true']);
        } catch (ConnectionException $e) {
            Log::warning('Ashby canonical retrieval: connection failure.', ['board' => $boardIdentifier]);

            throw new DiscoveryProviderException('Ashby request failed: connection error.', previous: $e);
        }

        if ($response->failed()) {
            Log::warning('Ashby canonical retrieval: request failed.', ['board' => $boardIdentifier, 'status' => $response->status()]);

            throw new DiscoveryProviderException("Ashby request failed with HTTP status {$response->status()}.");
        }

        $jobs = $response->json('jobs');

        if (! is_array($jobs)) {
            throw new DiscoveryProviderException('Ashby response did not contain a jobs array — response shape may have changed.');
        }

        return array_values(array_filter(array_map(
            fn (array $job) => $this->normalize($job),
            $jobs,
        )));
    }

    /**
     * @param  array<string, mixed>  $job
     */
    private function normalize(array $job): ?CanonicalJobPosting
    {
        $id = $job['id'] ?? null;
        $title = $job['title'] ?? null;

        if (! is_string($id) || ! is_string($title)) {
            return null;
        }

        return new CanonicalJobPosting(
            canonicalSource: JobCanonicalSource::Ashby,
            canonicalSourceId: $id,
            title: $title,
            description: is_string($job['descriptionPlain'] ?? null) ? $job['descriptionPlain'] : null,
            location: is_string($job['location'] ?? null) ? $job['location'] : null,
            remoteStatus: $this->normalizeRemote($job),
            employmentType: is_string($job['employmentType'] ?? null) ? $job['employmentType'] : null,
            compensationMin: null,
            compensationMax: null,
            compensationCurrency: null,
            compensationInterval: null,
            applicationUrl: is_string($job['jobUrl'] ?? null) ? $job['jobUrl'] : null,
            sourceUpdatedAt: $this->parseTimestamp($job['publishedAt'] ?? null),
            sourceMetadata: array_filter([
                'department' => $job['department'] ?? null,
                'team' => $job['team'] ?? null,
                'compensationSummary' => $job['compensation']['compensationTierSummary'] ?? null,
            ], fn ($value) => $value !== null),
        );
    }

    /**
     * @param  array<string, mixed>  $job
     */
    private function normalizeRemote(array $job): ?JobRemoteStatus
    {
        if (($job['isRemote'] ?? null) === true) {
            return JobRemoteStatus::Remote;
        }

        return match (mb_strtolower((string) ($job['workplaceType'] ?? ''))) {
            'remote' => JobRemoteStatus::Remote,
            'hybrid' => JobRemoteStatus::Hybrid,
            'onsite' => JobRemoteStatus::Onsite,
            default => null,
        };
    }

    private function parseTimestamp(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (Throwable) {
            return null;
        }
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
