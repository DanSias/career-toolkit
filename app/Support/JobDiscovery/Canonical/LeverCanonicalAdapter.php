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
 * Lever's public postings API (api.lever.co) — free, no
 * authentication, live-verified during the Automatic Job Discovery
 * investigation and again directly against this exact adapter. Real
 * pagination via skip/limit is available but not needed here: a
 * single request with a generous limit covers every real board size
 * observed during investigation (largest tested: ~4,500 postings on
 * an aggregator's own Lever board; a typical employer board is far
 * smaller) — revisit with real skip/limit paging only if evidence
 * shows a board this app actually resolves to exceeds one page.
 */
final class LeverCanonicalAdapter implements CanonicalRetrievalContract
{
    private const TIMEOUT_SECONDS = 30;

    private const RETRY_TIMES = 3;

    private const RETRY_DELAY_MILLISECONDS = 1000;

    private const RETRYABLE_STATUSES = [408, 429, 500, 502, 503, 504];

    private const PAGE_LIMIT = 200;

    public function retrieveBoard(string $boardIdentifier): array
    {
        $endpoint = "https://api.lever.co/v0/postings/{$boardIdentifier}";

        try {
            $response = Http::timeout(self::TIMEOUT_SECONDS)
                ->retry(
                    self::RETRY_TIMES,
                    self::RETRY_DELAY_MILLISECONDS,
                    fn (Throwable $e) => $this->isRetryable($e),
                    throw: false,
                )
                ->get($endpoint, ['mode' => 'json', 'limit' => self::PAGE_LIMIT]);
        } catch (ConnectionException $e) {
            Log::warning('Lever canonical retrieval: connection failure.', ['board' => $boardIdentifier]);

            throw new DiscoveryProviderException('Lever request failed: connection error.', previous: $e);
        }

        if ($response->failed()) {
            Log::warning('Lever canonical retrieval: request failed.', ['board' => $boardIdentifier, 'status' => $response->status()]);

            throw new DiscoveryProviderException("Lever request failed with HTTP status {$response->status()}.");
        }

        $jobs = $response->json();

        if (! is_array($jobs) || ! array_is_list($jobs)) {
            throw new DiscoveryProviderException('Lever response was not a JSON array — response shape may have changed.');
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
    private function normalize(array $job): ?CanonicalJobPosting
    {
        $id = $job['id'] ?? null;
        $title = $job['text'] ?? null;

        if (! is_string($id) || ! is_string($title)) {
            return null;
        }

        $categories = is_array($job['categories'] ?? null) ? $job['categories'] : [];
        $salary = is_array($job['salaryRange'] ?? null) ? $job['salaryRange'] : [];

        return new CanonicalJobPosting(
            canonicalSource: JobCanonicalSource::Lever,
            canonicalSourceId: $id,
            title: $title,
            description: is_string($job['descriptionPlain'] ?? null) ? $job['descriptionPlain'] : null,
            location: is_string($categories['location'] ?? null) ? $categories['location'] : null,
            remoteStatus: $this->normalizeWorkplaceType($job['workplaceType'] ?? null),
            employmentType: is_string($categories['commitment'] ?? null) ? $categories['commitment'] : null,
            compensationMin: is_numeric($salary['min'] ?? null) ? (int) $salary['min'] : null,
            compensationMax: is_numeric($salary['max'] ?? null) ? (int) $salary['max'] : null,
            compensationCurrency: is_string($salary['currency'] ?? null) ? $salary['currency'] : null,
            compensationInterval: is_string($salary['interval'] ?? null) ? $salary['interval'] : null,
            applicationUrl: is_string($job['hostedUrl'] ?? null) ? $job['hostedUrl'] : null,
            sourceUpdatedAt: $this->parseMillisecondTimestamp($job['createdAt'] ?? null),
            sourceMetadata: array_filter([
                'team' => $categories['team'] ?? null,
                'department' => $categories['department'] ?? null,
                'country' => $job['country'] ?? null,
            ], fn ($value) => $value !== null),
        );
    }

    private function normalizeWorkplaceType(mixed $value): ?JobRemoteStatus
    {
        if (! is_string($value)) {
            return null;
        }

        return match (mb_strtolower($value)) {
            'remote' => JobRemoteStatus::Remote,
            'hybrid' => JobRemoteStatus::Hybrid,
            'on-site', 'onsite' => JobRemoteStatus::Onsite,
            default => null,
        };
    }

    private function parseMillisecondTimestamp(mixed $value): ?CarbonImmutable
    {
        if (! is_numeric($value)) {
            return null;
        }

        return CarbonImmutable::createFromTimestampMs((int) $value);
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
