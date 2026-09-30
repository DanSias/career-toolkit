<?php

namespace App\Support\JobDiscovery\Canonical;

use App\Enums\DescriptionCompleteness;
use App\Enums\JobCanonicalSource;
use App\Support\JobDiscovery\Providers\DiscoveryProviderException;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Greenhouse's public job-board API (boards-api.greenhouse.io) — free,
 * no authentication, live-verified during the Automatic Job Discovery
 * investigation and again directly against this exact adapter.
 * `?content=true` on the LIST endpoint returns full descriptions plus
 * departments/offices for every job in one call — confirmed directly;
 * no per-job detail call is needed. Compensation
 * (`pay_input_ranges`) exists in Greenhouse's schema but was null on
 * every real job checked across every employer tested — never assumed
 * present.
 */
final class GreenhouseCanonicalAdapter implements CanonicalRetrievalContract
{
    private const TIMEOUT_SECONDS = 30;

    private const RETRY_TIMES = 3;

    private const RETRY_DELAY_MILLISECONDS = 1000;

    private const RETRYABLE_STATUSES = [408, 429, 500, 502, 503, 504];

    public function retrieveBoard(string $boardIdentifier): array
    {
        $endpoint = "https://boards-api.greenhouse.io/v1/boards/{$boardIdentifier}/jobs";

        try {
            $response = Http::timeout(self::TIMEOUT_SECONDS)
                ->retry(
                    self::RETRY_TIMES,
                    self::RETRY_DELAY_MILLISECONDS,
                    fn (Throwable $e) => $this->isRetryable($e),
                    throw: false,
                )
                ->get($endpoint, ['content' => 'true']);
        } catch (ConnectionException $e) {
            Log::warning('Greenhouse canonical retrieval: connection failure.', ['board' => $boardIdentifier]);

            throw new DiscoveryProviderException('Greenhouse request failed: connection error.', previous: $e);
        }

        if ($response->failed()) {
            Log::warning('Greenhouse canonical retrieval: request failed.', ['board' => $boardIdentifier, 'status' => $response->status()]);

            throw new DiscoveryProviderException("Greenhouse request failed with HTTP status {$response->status()}.");
        }

        $jobs = $response->json('jobs');

        if (! is_array($jobs)) {
            throw new DiscoveryProviderException('Greenhouse response did not contain a jobs array — response shape may have changed.');
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
        $title = $job['title'] ?? null;

        if (! is_int($id) || ! is_string($title)) {
            return null;
        }

        return new CanonicalJobPosting(
            canonicalSource: JobCanonicalSource::Greenhouse,
            canonicalSourceId: (string) $id,
            title: $title,
            description: is_string($job['content'] ?? null) ? $job['content'] : null,
            // Greenhouse's `content` (with ?content=true) is the full
            // posting body, confirmed live — 201/201 real jobs on a
            // tested board carried complete content inline, no
            // per-job call needed. See this class's own docblock.
            descriptionCompleteness: DescriptionCompleteness::Complete,
            location: is_string($job['location']['name'] ?? null) ? $job['location']['name'] : null,
            remoteStatus: null,
            employmentType: null,
            compensationMin: null,
            compensationMax: null,
            compensationCurrency: null,
            compensationInterval: null,
            applicationUrl: is_string($job['absolute_url'] ?? null) ? $job['absolute_url'] : null,
            sourceUpdatedAt: $this->parseTimestamp($job['updated_at'] ?? null),
            sourceMetadata: array_filter([
                'requisition_id' => $job['requisition_id'] ?? null,
                'departments' => array_map(fn ($d) => $d['name'] ?? null, $job['departments'] ?? []) ?: null,
                'offices' => array_map(fn ($o) => $o['name'] ?? null, $job['offices'] ?? []) ?: null,
            ], fn ($value) => $value !== null),
        );
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
