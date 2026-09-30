<?php

namespace App\Support\JobDiscovery;

use App\Models\JobPosting;

final readonly class IngestResult
{
    public function __construct(
        public JobPosting $jobPosting,
        public bool $wasCreated,
        public bool $wasCanonicalized,
    ) {}
}
