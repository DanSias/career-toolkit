<?php

namespace App\Support\JobDiscovery;

use App\Enums\DiscoveryStatus;
use App\Models\DiscoveryRun;

/**
 * The staleness check bootstrap/app.php's schedule definition gates
 * discovery:run on — extracted to its own directly-testable class
 * rather than an inline schedule closure, since Laravel's Schedule/
 * Event internals aren't a convenient thing to unit test against
 * directly. See docs/job-discovery.md "Scheduling / orchestration".
 */
final class DiscoveryScheduleIsDue
{
    public function __invoke(): bool
    {
        $staleAfterHours = (int) config('job_discovery.schedule.stale_after_hours');

        $lastRun = DiscoveryRun::query()
            ->where('status', DiscoveryStatus::Succeeded)
            ->latest('finished_at')
            ->first();

        if ($lastRun === null || $lastRun->finished_at === null) {
            return true;
        }

        return $lastRun->finished_at->lt(now()->subHours($staleAfterHours));
    }
}
