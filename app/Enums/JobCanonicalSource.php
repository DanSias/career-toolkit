<?php

namespace App\Enums;

/**
 * Which ATS authoritatively OWNS a JobPosting, once canonical
 * enrichment has resolved it — null on the model until then. See
 * App\Enums\JobDiscoverySource's docblock for why this is a separate
 * field/enum from discovery_source, never overloaded into one.
 */
enum JobCanonicalSource: string
{
    case Greenhouse = 'greenhouse';
    case Lever = 'lever';
    case Ashby = 'ashby';
}
