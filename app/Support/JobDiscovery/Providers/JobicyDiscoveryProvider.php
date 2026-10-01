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
 * Jobicy's public remote-jobs API (jobicy.com/api/v2/remote-jobs) —
 * free, no authentication, live-verified during the Automatic Job
 * Discovery investigation and again directly against this exact
 * provider. `jobDescription` is the full HTML posting body (a real
 * sample ran 8,900+ characters with Responsibilities/Qualifications
 * sections, `<ul><li>` lists, and no truncation) — deliberately
 * distinct from the short `jobExcerpt` field Jobicy also returns,
 * which is never used here.
 *
 * `geo=usa` is a real, confirmed provider-side filter (echoed back in
 * the response's own `appliedFilters`) — used here instead of pulling
 * every country and filtering client-side, per this checkpoint's
 * instructions. No other query parameter is used: `industry`/`tag`
 * were probed live during implementation and showed non-obvious,
 * seemingly coercive/substring matching behavior (e.g. `industry=dev`
 * silently became `industry=engineering`; `tag=dev` matched "Sales
 * Development") that would not be deterministic/conservative — all
 * role/keyword/seniority filtering is left to
 * App\Support\JobDiscovery\FilterDiscoveredCandidates instead, same
 * as every other provider.
 *
 * Every Jobicy job is remote by construction (it is a remote-jobs-only
 * feed, same as Himalayas) — remoteStatus is always Remote here, a
 * platform-level fact, not a per-job inference.
 *
 * `count` is capped by the feed itself at 200 regardless of what's
 * requested — confirmed live (requesting 500 returned exactly 200).
 * No further pagination is implemented; see
 * App\Support\JobDiscovery\Providers\HimalayasDiscoveryProvider's own
 * docblock for the identical precedent.
 *
 * Jobicy's own API response asks that application buttons redirect to
 * "the original job URL provided in this feed" — `url` (Jobicy's own
 * job page) is that URL for this feed, consistent with how
 * Himalayas' `applicationLink` is also the aggregator's own page
 * rather than necessarily the employer's ATS; canonical enrichment
 * (App\Support\JobDiscovery\Canonical) still takes over the
 * authoritative URL when a company resolves to a known ATS board.
 */
final class JobicyDiscoveryProvider implements DiscoveryProviderContract
{
    private const TIMEOUT_SECONDS = 30;

    private const RETRY_TIMES = 3;

    private const RETRY_DELAY_MILLISECONDS = 1000;

    private const RETRYABLE_STATUSES = [408, 429, 500, 502, 503, 504];

    public function __construct(
        // Requested as an upper bound — the live feed currently caps
        // the real response at 200 regardless of this value. See this
        // class's own docblock.
        private readonly int $count = 200,
    ) {}

    /**
     * @return DiscoveredJobCandidate[]
     */
    public function retrieve(): array
    {
        $baseUrl = (string) config('services.jobicy.base_url');

        try {
            $response = Http::timeout(self::TIMEOUT_SECONDS)
                ->retry(
                    self::RETRY_TIMES,
                    self::RETRY_DELAY_MILLISECONDS,
                    fn (Throwable $e) => $this->isRetryable($e),
                    throw: false,
                )
                ->get($baseUrl, ['count' => $this->count, 'geo' => 'usa']);
        } catch (ConnectionException $e) {
            Log::warning('Jobicy discovery: connection failure.');

            throw new DiscoveryProviderException('Jobicy request failed: connection error.', previous: $e);
        }

        if ($response->failed()) {
            Log::warning('Jobicy discovery: request failed.', ['status' => $response->status()]);

            throw new DiscoveryProviderException("Jobicy request failed with HTTP status {$response->status()}.");
        }

        $jobs = $response->json('jobs');

        if (! is_array($jobs)) {
            throw new DiscoveryProviderException('Jobicy response did not contain a jobs array — response shape may have changed.');
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
        $sourceJobId = $job['id'] ?? null;
        $title = $job['jobTitle'] ?? null;
        $company = $job['companyName'] ?? null;

        if ((! is_string($sourceJobId) && ! is_int($sourceJobId)) || ! is_string($title) || ! is_string($company)) {
            return null;
        }

        $jobType = is_array($job['jobType'] ?? null) ? array_values(array_filter($job['jobType'], 'is_string')) : [];

        return new DiscoveredJobCandidate(
            source: JobDiscoverySource::Jobicy,
            sourceJobId: (string) $sourceJobId,
            company: $this->decodeEntities($company),
            title: $this->decodeEntities($title),
            description: is_string($job['jobDescription'] ?? null) ? $this->htmlToPlainText($job['jobDescription']) : null,
            // jobDescription is the feed's full posting body — confirmed
            // live, not the short jobExcerpt field. See this class's
            // own docblock.
            descriptionCompleteness: DescriptionCompleteness::Complete,
            location: is_string($job['jobGeo'] ?? null) ? trim($job['jobGeo']) : null,
            remoteStatus: JobRemoteStatus::Remote,
            employmentType: $jobType[0] ?? null,
            compensationMin: is_numeric($job['salaryMin'] ?? null) ? (int) $job['salaryMin'] : null,
            compensationMax: is_numeric($job['salaryMax'] ?? null) ? (int) $job['salaryMax'] : null,
            compensationCurrency: is_string($job['salaryCurrency'] ?? null) ? $job['salaryCurrency'] : null,
            compensationInterval: is_string($job['salaryPeriod'] ?? null) ? $job['salaryPeriod'] : null,
            applicationUrl: is_string($job['url'] ?? null) ? $job['url'] : null,
            postedAt: $this->parseTimestamp($job['pubDate'] ?? null),
            sourceUpdatedAt: null,
            discoveredAt: now(),
            sourceMetadata: array_filter([
                'jobLevel' => $job['jobLevel'] ?? null,
                'jobIndustry' => $job['jobIndustry'] ?? null,
                'jobSlug' => $job['jobSlug'] ?? null,
            ], fn ($value) => $value !== null),
        );
    }

    /**
     * Minimal, deterministic HTML-to-plain-text conversion for
     * Jobicy's `jobDescription` — not a general document parser.
     * Block-level tags become line breaks and list items get a
     * leading "- " so the posting's real structure (seen live:
     * headings, paragraphs, `<ul><li>` lists) survives as readable
     * plain text rather than running together.
     */
    private function htmlToPlainText(string $html): string
    {
        $withBullets = preg_replace('~<li[^>]*>~i', "\n- ", $html) ?? $html;
        $withBreaks = preg_replace('~</(p|div|h[1-6]|li|ul|ol)>~i', "\n", $withBullets) ?? $withBullets;
        $withBreaks = preg_replace('~<br\s*/?>~i', "\n", $withBreaks) ?? $withBreaks;
        $plain = html_entity_decode(strip_tags($withBreaks), ENT_QUOTES | ENT_HTML5);
        $plain = str_replace("\u{A0}", ' ', $plain);
        $plain = preg_replace('/[ \t]+/', ' ', $plain) ?? $plain;
        $plain = preg_replace('/\n[ \t]+/', "\n", $plain) ?? $plain;
        $plain = preg_replace('/\n{3,}/', "\n\n", trim($plain)) ?? $plain;

        return trim($plain);
    }

    /**
     * Jobicy's `companyName`/`jobTitle` fields come through
     * HTML-entity-encoded (confirmed live — e.g. "hims &#038; hers"
     * instead of "hims & hers") even though they carry no markup of
     * their own. A plain entity decode is enough; unlike
     * htmlToPlainText() above, there are no tags to strip or block
     * structure to preserve here.
     */
    private function decodeEntities(string $value): string
    {
        return html_entity_decode($value, ENT_QUOTES | ENT_HTML5);
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
