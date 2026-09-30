<?php

namespace App\Support\JobDiscovery;

use App\Enums\DiscoveryStatus;
use App\Enums\JobCanonicalSource;
use App\Enums\JobDiscoverySource;
use App\Models\CareerProfile;
use App\Models\DiscoveryRun;
use App\Support\CurrentCareerProfile;
use App\Support\JobDiscovery\Canonical\AshbyCanonicalAdapter;
use App\Support\JobDiscovery\Canonical\CanonicalJobPosting;
use App\Support\JobDiscovery\Canonical\CanonicalRetrievalContract;
use App\Support\JobDiscovery\Canonical\GreenhouseCanonicalAdapter;
use App\Support\JobDiscovery\Canonical\LeverCanonicalAdapter;
use App\Support\JobDiscovery\Canonical\MatchCanonicalPosting;
use App\Support\JobDiscovery\Canonical\ResolveAtsBoard;
use App\Support\JobDiscovery\Providers\AdzunaDiscoveryProvider;
use App\Support\JobDiscovery\Providers\DiscoveryProviderContract;
use App\Support\JobDiscovery\Providers\DiscoveryProviderException;
use App\Support\JobDiscovery\Providers\HimalayasDiscoveryProvider;
use Throwable;

/**
 * The single discovery pipeline both `php artisan discovery:run` and
 * (a later commit's) scheduled execution call — never two separate
 * implementations. See docs/job-discovery.md "Scheduling /
 * orchestration".
 *
 * Provider attempts settle on expected and unexpected execution failures.
 * Earlier candidate transactions remain committed; independent providers
 * continue. Identity conflicts are reported without merging existing rows.
 *
 * Never wrapped in one all-or-nothing transaction — each candidate's
 * ingest() call is its own small transaction (see
 * IngestDiscoveredCandidate), not the whole run.
 */
final class RunJobDiscovery
{
    /**
     * @var array<string, DiscoveryProviderContract>
     */
    private readonly array $providers;

    /**
     * @var array<string, CanonicalRetrievalContract>
     */
    private readonly array $canonicalAdapters;

    public function __construct(
        ?HimalayasDiscoveryProvider $himalayas = null,
        ?AdzunaDiscoveryProvider $adzuna = null,
        private readonly FilterDiscoveredCandidates $filter = new FilterDiscoveredCandidates,
        private readonly ResolveAtsBoard $resolveBoard = new ResolveAtsBoard,
        private readonly MatchCanonicalPosting $matchCanonical = new MatchCanonicalPosting,
        private readonly IngestDiscoveredCandidate $ingestCandidate = new IngestDiscoveredCandidate,
        ?GreenhouseCanonicalAdapter $greenhouse = null,
        ?LeverCanonicalAdapter $lever = null,
        ?AshbyCanonicalAdapter $ashby = null,
    ) {
        $this->providers = [
            JobDiscoverySource::Himalayas->value => $himalayas ?? new HimalayasDiscoveryProvider,
            JobDiscoverySource::Adzuna->value => $adzuna ?? new AdzunaDiscoveryProvider,
        ];

        $this->canonicalAdapters = [
            JobCanonicalSource::Greenhouse->value => $greenhouse ?? new GreenhouseCanonicalAdapter,
            JobCanonicalSource::Lever->value => $lever ?? new LeverCanonicalAdapter,
            JobCanonicalSource::Ashby->value => $ashby ?? new AshbyCanonicalAdapter,
        ];
    }

    public function run(): DiscoveryRun
    {
        $careerProfile = CurrentCareerProfile::resolve();
        $criteria = DiscoverySearchCriteria::fromConfig();

        $discoveryRun = DiscoveryRun::create([
            'status' => DiscoveryStatus::Running,
            'started_at' => now(),
        ]);

        // Resolved boards are cached per run — several candidates from
        // different providers may resolve to the same company, and
        // there is no reason to re-fetch that board's full listing
        // more than once per run.
        $boardCache = [];

        $orchestrationFailed = false;
        try {
            foreach ($this->providers as $source => $provider) {
                $this->runProviderAttempt($discoveryRun, JobDiscoverySource::from($source), $provider, $criteria, $careerProfile, $boardCache);
            }
        } catch (Throwable) {
            $orchestrationFailed = true;
            $discoveryRun->providerAttempts()->where('status', DiscoveryStatus::Running)->update([
                'status' => DiscoveryStatus::Failed,
                'finished_at' => now(),
                'failure_category' => 'unexpected_error',
                'failure_message' => 'Unexpected discovery orchestration failure.',
            ]);
        }

        $attempts = $discoveryRun->providerAttempts()->get();
        $failures = $attempts->where('status', DiscoveryStatus::Failed)->count();
        $status = $failures === 0 && ! $orchestrationFailed ? DiscoveryStatus::Succeeded
            : ($failures < $attempts->count() ? DiscoveryStatus::Partial : DiscoveryStatus::Failed);
        $discoveryRun->update(['status' => $status, 'finished_at' => now()]);

        return $discoveryRun->fresh('providerAttempts');
    }

    /**
     * @param  array<string, CanonicalJobPosting[]>  $boardCache
     */
    private function runProviderAttempt(
        DiscoveryRun $discoveryRun,
        JobDiscoverySource $source,
        DiscoveryProviderContract $provider,
        DiscoverySearchCriteria $criteria,
        CareerProfile $careerProfile,
        array &$boardCache,
    ): void {
        $attempt = $discoveryRun->providerAttempts()->create([
            'provider' => $source,
            'status' => DiscoveryStatus::Running,
            'started_at' => now(),
        ]);

        $retrieved = $accepted = $created = $updated = $canonicalized = 0;
        $failureCategory = $failureMessage = null;

        try {
            $candidates = $provider->retrieve();
            $retrieved = count($candidates);
            foreach ($candidates as $candidate) {
                if (! $this->filter->accepts($candidate, $criteria)) {
                    continue;
                }
                $accepted++;
                $canonicalMatch = $this->resolveCanonicalMatch($candidate, $boardCache);
                try {
                    $result = $this->ingestCandidate->ingest($candidate, $canonicalMatch, $careerProfile);
                } catch (DiscoveryIngestionException $e) {
                    // Preserve both existing identities and continue independent
                    // candidates. This attempt is terminal failed, with partial counts.
                    $failureCategory = 'identity_conflict';
                    $failureMessage = $e->getMessage();

                    continue;
                }
                $canonicalized += $canonicalMatch !== null ? 1 : 0;
                if ($result->wasCreated) {
                    $created++;
                } else {
                    $updated++;
                }
            }
        } catch (DiscoveryProviderException $e) {
            $failureCategory = 'provider_error';
            $failureMessage = $e->getMessage();
        } catch (Throwable) {
            // Never persist arbitrary exception messages: they can contain SQL,
            // provider payloads or credential-bearing request URLs.
            $failureCategory = 'unexpected_error';
            $failureMessage = 'Unexpected discovery execution failure; completed candidate writes were retained.';
        }

        $attempt->update([
            'status' => $failureCategory === null ? DiscoveryStatus::Succeeded : DiscoveryStatus::Failed,
            'finished_at' => now(),
            'candidates_retrieved' => $retrieved,
            'candidates_accepted' => $accepted,
            'jobs_created' => $created,
            'jobs_updated' => $updated,
            'jobs_canonicalized' => $canonicalized,
            'failure_category' => $failureCategory,
            'failure_message' => $failureMessage,
        ]);
    }

    /**
     * @param  array<string, CanonicalJobPosting[]>  $boardCache
     */
    private function resolveCanonicalMatch(DiscoveredJobCandidate $candidate, array &$boardCache): ?CanonicalJobPosting
    {
        $board = $this->resolveBoard->resolve($candidate->company);

        if ($board === null) {
            return null;
        }

        $cacheKey = "{$board->ats_type->value}:{$board->board_identifier}";

        if (! array_key_exists($cacheKey, $boardCache)) {
            // Every JobCanonicalSource case has an adapter registered
            // in the constructor — the lookup below always succeeds.
            $adapter = $this->canonicalAdapters[$board->ats_type->value];

            try {
                $boardCache[$cacheKey] = $adapter->retrieveBoard($board->board_identifier);
            } catch (DiscoveryProviderException) {
                // Canonical retrieval failing is an enhancement
                // failing, not an ingestion blocker — the candidate
                // still proceeds, aggregator-only. See
                // docs/job-discovery.md "Canonical resolution must not
                // block ingestion".
                $boardCache[$cacheKey] = [];
            }
        }

        return $this->matchCanonical->match($candidate, $boardCache[$cacheKey]);
    }
}
