<?php

namespace App\Enums;

/**
 * Where a JobPosting was FOUND — the discovery-side identity, kept
 * deliberately separate from App\Enums\JobCanonicalSource (which
 * ATS system authoritatively OWNS the posting, once resolved). A
 * JobPosting discovered via Himalayas and later canonically enriched
 * via Greenhouse keeps `discovery_source: himalayas` forever — see
 * docs/job-discovery.md "Discovery identity vs. canonical identity".
 *
 * `Manual` is the value every hand-created JobPosting gets (the
 * column default) — it is the discriminator between "immutable,
 * captured-once intake snapshot" and "live, periodically-refreshed
 * external posting" (App\Models\JobPosting::isDiscovered()). Manual
 * postings are never touched by discovery's ingestion/update logic,
 * because that logic only ever queries by (discovery_source,
 * discovery_source_id) for a real provider value — `manual` rows
 * never populate discovery_source_id, so they are structurally
 * unreachable by it, not merely convention.
 */
enum JobDiscoverySource: string
{
    case Manual = 'manual';
    case Himalayas = 'himalayas';
    case Adzuna = 'adzuna';
}
