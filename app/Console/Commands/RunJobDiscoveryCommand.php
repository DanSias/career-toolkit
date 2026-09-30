<?php

namespace App\Console\Commands;

use App\Enums\DiscoveryStatus;
use App\Support\JobDiscovery\RunJobDiscovery;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Manual trigger for the discovery pipeline — calls the exact same
 * App\Support\JobDiscovery\RunJobDiscovery service a later commit's
 * scheduled execution also calls. No separate manual-vs-scheduled
 * implementation exists. See docs/job-discovery.md "Manual discovery
 * command".
 */
#[Signature('discovery:run')]
#[Description('Run the automatic job discovery pipeline once: query providers, filter, canonically enrich, deduplicate, and ingest into JobPosting.')]
class RunJobDiscoveryCommand extends Command
{
    public function handle(RunJobDiscovery $runJobDiscovery): int
    {
        $this->components->info('Running job discovery…');

        $discoveryRun = $runJobDiscovery->run();

        $rows = $discoveryRun->providerAttempts->map(fn ($attempt) => [
            $attempt->provider->value,
            $attempt->status->value,
            $attempt->candidates_retrieved ?? '—',
            $attempt->candidates_accepted ?? '—',
            $attempt->jobs_created ?? '—',
            $attempt->jobs_updated ?? '—',
            $attempt->jobs_canonicalized ?? '—',
            $attempt->failure_message ?? '',
        ])->all();

        $this->table(
            ['Provider', 'Status', 'Retrieved', 'Accepted', 'Created', 'Updated', 'Canonicalized', 'Failure'],
            $rows,
        );

        $anyFailed = $discoveryRun->providerAttempts->contains(fn ($attempt) => $attempt->status === DiscoveryStatus::Failed);

        if ($anyFailed) {
            $this->components->warn('At least one provider failed this run — see the table above. Other providers\' results were still ingested.');
        }

        $this->components->info("Discovery run #{$discoveryRun->id} finished with status {$discoveryRun->status->value}.");

        return self::SUCCESS;
    }
}
