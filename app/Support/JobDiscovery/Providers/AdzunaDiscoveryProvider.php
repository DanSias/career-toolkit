<?php

namespace App\Support\JobDiscovery\Providers;

use App\Enums\JobDiscoverySource;
use App\Support\JobDiscovery\DiscoveredJobCandidate;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Adzuna's public job-search API (developer.adzuna.com) — free
 * app_id/app_key required. Response shape confirmed against Adzuna's
 * own official documentation (developer.adzuna.com/docs/search); see
 * docs/job-discovery.md "Discovery sources — Adzuna".
 *
 * A missing app_id/app_key fails only this provider's attempt closed
 * (App\Support\JobDiscovery\Providers\DiscoveryProviderException) —
 * never the whole discovery run, and never Himalayas.
 *
 * Quota discipline (2,500 calls/month, 25/min per the investigation):
 * one call per discovery run, `results_per_page` capped well under
 * Adzuna's own page-size ceiling — no generalized rate-limiter, this
 * volume naturally respects the quota by construction (see
 * docs/job-discovery.md "Cost / quota").
 */
final class AdzunaDiscoveryProvider implements DiscoveryProviderContract
{
    private const TIMEOUT_SECONDS = 30;

    private const RETRY_TIMES = 3;

    private const RETRY_DELAY_MILLISECONDS = 1000;

    private const RETRYABLE_STATUSES = [408, 429, 500, 502, 503, 504];

    public function __construct(
        private readonly int $resultsPerPage = 50,
    ) {}

    /**
     * @return DiscoveredJobCandidate[]
     */
    public function retrieve(): array
    {
        $appId = (string) config('services.adzuna.app_id');
        $appKey = (string) config('services.adzuna.app_key');
        $country = (string) config('services.adzuna.country');

        if ($appId === '' || $appKey === '') {
            throw new DiscoveryProviderException('Adzuna is not configured (ADZUNA_APP_ID/ADZUNA_APP_KEY).');
        }

        $endpoint = "https://api.adzuna.com/v1/api/jobs/{$country}/search/1";

        try {
            $response = Http::timeout(self::TIMEOUT_SECONDS)
                ->retry(
                    self::RETRY_TIMES,
                    self::RETRY_DELAY_MILLISECONDS,
                    fn (Throwable $e) => $this->isRetryable($e),
                    throw: false,
                )
                ->get($endpoint, [
                    'app_id' => $appId,
                    'app_key' => $appKey,
                    'results_per_page' => $this->resultsPerPage,
                    // A single representative role term — Adzuna's
                    // `what` does an AND match against the ad text, so
                    // it narrows the request itself; the full
                    // keyword/exclusion/seniority logic still runs
                    // client-side via FilterDiscoveredCandidates,
                    // never hardwired here.
                    'what' => 'software engineer',
                    'content-type' => 'application/json',
                ]);
        } catch (ConnectionException $e) {
            Log::warning('Adzuna discovery: connection failure.');

            throw new DiscoveryProviderException('Adzuna request failed: connection error.', previous: $e);
        }

        if ($response->failed()) {
            Log::warning('Adzuna discovery: request failed.', ['status' => $response->status()]);

            throw new DiscoveryProviderException("Adzuna request failed with HTTP status {$response->status()}.");
        }

        $results = $response->json('results');

        if (! is_array($results)) {
            throw new DiscoveryProviderException('Adzuna response did not contain a results array — response shape may have changed.');
        }

        return array_values(array_filter(array_map(
            fn (array $job) => $this->normalize($job, $country),
            $results,
        )));
    }

    /**
     * @param  array<string, mixed>  $job
     */
    private function normalize(array $job, string $country): ?DiscoveredJobCandidate
    {
        $sourceJobId = $job['id'] ?? null;
        $title = $job['title'] ?? null;
        $company = $job['company']['display_name'] ?? null;

        if (! is_string($sourceJobId) && ! is_int($sourceJobId)) {
            return null;
        }
        if (! is_string($title) || ! is_string($company)) {
            return null;
        }

        return new DiscoveredJobCandidate(
            source: JobDiscoverySource::Adzuna,
            sourceJobId: (string) $sourceJobId,
            company: $company,
            title: strip_tags($title),
            description: is_string($job['description'] ?? null) ? strip_tags($job['description']) : null,
            location: is_string($job['location']['display_name'] ?? null) ? $job['location']['display_name'] : null,
            // Adzuna's search API has no structured remote flag —
            // confirmed against the official response schema. Left
            // null rather than guessed; FilterDiscoveredCandidates
            // falls back to location-text matching for this provider.
            remoteStatus: null,
            employmentType: is_string($job['contract_time'] ?? null) ? $job['contract_time'] : null,
            compensationMin: is_numeric($job['salary_min'] ?? null) ? (int) $job['salary_min'] : null,
            compensationMax: is_numeric($job['salary_max'] ?? null) ? (int) $job['salary_max'] : null,
            // Adzuna's response carries no currency field — inferred
            // from the search country, since this app only ever
            // configures one (ADZUNA_COUNTRY).
            compensationCurrency: (isset($job['salary_min']) || isset($job['salary_max'])) ? $this->currencyForCountry($country) : null,
            // Adzuna's salary_min/max are annualized figures per its
            // own documentation, regardless of contract_time.
            compensationInterval: isset($job['salary_min']) || isset($job['salary_max']) ? 'year' : null,
            applicationUrl: is_string($job['redirect_url'] ?? null) ? $job['redirect_url'] : null,
            postedAt: $this->parseTimestamp($job['created'] ?? null),
            sourceUpdatedAt: null,
            discoveredAt: now(),
            sourceMetadata: array_filter([
                'category' => $job['category']['label'] ?? null,
                'contract_type' => $job['contract_type'] ?? null,
                'salary_is_predicted' => $job['salary_is_predicted'] ?? null,
            ], fn ($value) => $value !== null),
        );
    }

    private function currencyForCountry(string $country): string
    {
        return match ($country) {
            'us' => 'USD',
            'gb' => 'GBP',
            'ca' => 'CAD',
            'au' => 'AUD',
            default => 'USD',
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
