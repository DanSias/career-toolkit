<?php

namespace App\Enums;

/**
 * Whether a JobPosting's underlying external posting is still
 * believed open — meaningful only for discovered postings (see
 * App\Models\JobPosting::isDiscovered()); manual postings default to
 * Open and are never transitioned by discovery logic. A posting is
 * marked Closed after 2 consecutive discovery rechecks fail to find
 * it (see App\Support\JobDiscovery — never on the first miss, to
 * avoid a single transient fetch failure falsely closing a real
 * posting).
 */
enum JobPostingLifecycleStatus: string
{
    case Open = 'open';
    case Closed = 'closed';
}
