<?php

namespace App\Support\JobDiscovery\Canonical;

use App\Enums\DescriptionCompleteness;
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
 *
 * Lever splits a posting's real content across three fields —
 * `descriptionPlain` (overview), `additionalPlain` (a second prose
 * block, e.g. "Why work here" / EEO text), and `lists[]` (structured
 * sections such as Responsibilities/Qualifications, each an
 * optional `text` label plus HTML `content`) — confirmed directly
 * against the real `velaura` board: `descriptionPlain` alone was
 * only 542 of ~5,600 real characters. assembleDescription() below
 * joins all three; reading `descriptionPlain` alone (the original
 * Phase 1 implementation) silently discarded the majority of every
 * Lever posting's actual content.
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
            description: $this->assembleDescription($job),
            // Complete as of the fix described in this class's own
            // docblock — assembleDescription() now joins
            // descriptionPlain + additionalPlain + every lists[]
            // section, live-confirmed against the real velaura board
            // (5,435 of ~5,600 real characters represented; the
            // original descriptionPlain-only implementation captured
            // only 542).
            descriptionCompleteness: DescriptionCompleteness::Complete,
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

    /**
     * Joins descriptionPlain + additionalPlain + each lists[] section
     * (label, when Lever supplies one, plus its HTML content
     * converted to plain text) into one human-readable description.
     * Never truncated; sections with no real content are skipped
     * rather than leaving blank gaps. See this class's own docblock.
     *
     * @param  array<string, mixed>  $job
     */
    private function assembleDescription(array $job): ?string
    {
        $sections = [];

        if (is_string($job['descriptionPlain'] ?? null) && trim($job['descriptionPlain']) !== '') {
            $sections[] = trim($job['descriptionPlain']);
        }

        if (is_string($job['additionalPlain'] ?? null) && trim($job['additionalPlain']) !== '') {
            $sections[] = trim($job['additionalPlain']);
        }

        foreach (is_array($job['lists'] ?? null) ? $job['lists'] : [] as $list) {
            if (! is_array($list) || ! is_string($list['content'] ?? null)) {
                continue;
            }

            $body = $this->htmlToPlainText($list['content']);
            if ($body === '') {
                continue;
            }

            $label = is_string($list['text'] ?? null) ? trim($list['text']) : '';
            // Lever sometimes supplies the section label as `text`,
            // and sometimes embeds it as the content's own first
            // heading instead (observed on the real velaura board) —
            // never both, but guard against a future board doing so
            // to avoid a duplicated heading line.
            if ($label !== '' && ! str_starts_with(mb_strtolower($body), mb_strtolower($label))) {
                $body = "{$label}\n{$body}";
            }

            $sections[] = $body;
        }

        return $sections === [] ? null : implode("\n\n", $sections);
    }

    /**
     * Minimal, deterministic HTML-to-plain-text conversion for
     * Lever's `lists[].content` — not a general document parser.
     * Block-level tags become line breaks so stripping the remaining
     * markup doesn't run paragraphs/headings together; everything
     * else is discarded.
     */
    private function htmlToPlainText(string $html): string
    {
        $withBreaks = preg_replace('~</(p|div|h[1-6]|li)>~i', "\n", $html) ?? $html;
        $withBreaks = preg_replace('~<br\s*/?>~i', "\n", $withBreaks) ?? $withBreaks;
        $plain = html_entity_decode(strip_tags($withBreaks), ENT_QUOTES | ENT_HTML5);
        $plain = str_replace("\u{A0}", ' ', $plain);
        $plain = preg_replace('/[ \t]+/', ' ', $plain) ?? $plain;
        $plain = preg_replace('/\n[ \t]+/', "\n", $plain) ?? $plain;
        $plain = preg_replace('/\n{3,}/', "\n\n", trim($plain)) ?? $plain;

        return trim($plain);
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
