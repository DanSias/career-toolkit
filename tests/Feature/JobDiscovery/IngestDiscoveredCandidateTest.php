<?php

use App\Enums\DescriptionCompleteness;
use App\Enums\JobCanonicalSource;
use App\Enums\JobDiscoverySource;
use App\Enums\JobPostingLifecycleStatus;
use App\Models\CareerProfile;
use App\Models\JobPosting;
use App\Support\JobDiscovery\Canonical\CanonicalJobPosting;
use App\Support\JobDiscovery\DiscoveredJobCandidate;
use App\Support\JobDiscovery\DiscoveryIngestionException;
use App\Support\JobDiscovery\IngestDiscoveredCandidate;
use Carbon\CarbonImmutable;

function discoveryCandidate(array $overrides = []): DiscoveredJobCandidate
{
    return new DiscoveredJobCandidate(
        source: $overrides['source'] ?? JobDiscoverySource::Himalayas,
        sourceJobId: $overrides['sourceJobId'] ?? 'himalayas-1',
        company: $overrides['company'] ?? 'Acme',
        title: $overrides['title'] ?? 'Senior Software Engineer',
        description: array_key_exists('description', $overrides) ? $overrides['description'] : 'Build things.',
        descriptionCompleteness: $overrides['descriptionCompleteness'] ?? DescriptionCompleteness::Complete,
        location: array_key_exists('location', $overrides) ? $overrides['location'] : 'United States',
        remoteStatus: $overrides['remoteStatus'] ?? null,
        employmentType: $overrides['employmentType'] ?? null,
        compensationMin: $overrides['compensationMin'] ?? null,
        compensationMax: $overrides['compensationMax'] ?? null,
        compensationCurrency: $overrides['compensationCurrency'] ?? null,
        compensationInterval: $overrides['compensationInterval'] ?? null,
        applicationUrl: array_key_exists('applicationUrl', $overrides) ? $overrides['applicationUrl'] : 'https://himalayas.app/jobs/1',
        postedAt: $overrides['postedAt'] ?? null,
        sourceUpdatedAt: $overrides['sourceUpdatedAt'] ?? null,
        discoveredAt: $overrides['discoveredAt'] ?? CarbonImmutable::now(),
        sourceMetadata: $overrides['sourceMetadata'] ?? [],
    );
}

function canonicalMatch(array $overrides = []): CanonicalJobPosting
{
    return new CanonicalJobPosting(
        canonicalSource: $overrides['canonicalSource'] ?? JobCanonicalSource::Greenhouse,
        canonicalSourceId: $overrides['canonicalSourceId'] ?? 'gh-1',
        title: $overrides['title'] ?? 'Senior Software Engineer',
        description: $overrides['description'] ?? 'Canonical description.',
        descriptionCompleteness: $overrides['descriptionCompleteness'] ?? DescriptionCompleteness::Complete,
        location: $overrides['location'] ?? 'New York, NY',
        remoteStatus: $overrides['remoteStatus'] ?? null,
        employmentType: $overrides['employmentType'] ?? null,
        compensationMin: $overrides['compensationMin'] ?? 150000,
        compensationMax: $overrides['compensationMax'] ?? 190000,
        compensationCurrency: $overrides['compensationCurrency'] ?? 'USD',
        compensationInterval: $overrides['compensationInterval'] ?? 'year',
        applicationUrl: array_key_exists('applicationUrl', $overrides) ? $overrides['applicationUrl'] : 'https://job-boards.greenhouse.io/acme/jobs/1',
        sourceUpdatedAt: $overrides['sourceUpdatedAt'] ?? null,
        sourceMetadata: $overrides['sourceMetadata'] ?? [],
    );
}

it('creates a new JobPosting for a genuinely new candidate', function () {
    $profile = CareerProfile::factory()->create();

    $result = (new IngestDiscoveredCandidate)->ingest(discoveryCandidate(), null, $profile);

    expect($result->wasCreated)->toBeTrue()
        ->and($result->wasCanonicalized)->toBeFalse()
        ->and($result->jobPosting->discovery_source)->toBe(JobDiscoverySource::Himalayas)
        ->and($result->jobPosting->discovery_source_id)->toBe('himalayas-1')
        ->and($result->jobPosting->canonical_source)->toBeNull()
        ->and($result->jobPosting->source_url)->toBe('https://himalayas.app/jobs/1');
});

it('prefers canonical data over discovery data when both are present', function () {
    $profile = CareerProfile::factory()->create();

    $result = (new IngestDiscoveredCandidate)->ingest(discoveryCandidate(), canonicalMatch(), $profile);

    expect($result->wasCanonicalized)->toBeTrue()
        ->and($result->jobPosting->description)->toBe('Canonical description.')
        ->and($result->jobPosting->source_url)->toBe('https://job-boards.greenhouse.io/acme/jobs/1')
        ->and($result->jobPosting->canonical_source)->toBe(JobCanonicalSource::Greenhouse)
        ->and($result->jobPosting->compensation_min)->toBe(150000);
});

it('tier 1: updates in place rather than duplicating on an exact (discovery_source, discovery_source_id) rematch', function () {
    $profile = CareerProfile::factory()->create();
    $first = (new IngestDiscoveredCandidate)->ingest(discoveryCandidate(['title' => 'Old Title']), null, $profile);

    $second = (new IngestDiscoveredCandidate)->ingest(discoveryCandidate(['title' => 'New Title']), null, $profile);

    expect($second->wasCreated)->toBeFalse()
        ->and($second->jobPosting->id)->toBe($first->jobPosting->id)
        ->and($second->jobPosting->title)->toBe('New Title')
        ->and(JobPosting::count())->toBe(1);
});

it('tier 1: bumps last_checked_at on rediscovery', function () {
    $profile = CareerProfile::factory()->create();
    $first = (new IngestDiscoveredCandidate)->ingest(discoveryCandidate(), null, $profile);
    $originalCheckedAt = $first->jobPosting->last_checked_at;

    CarbonImmutable::setTestNow(CarbonImmutable::now()->addHour());
    $second = (new IngestDiscoveredCandidate)->ingest(discoveryCandidate(), null, $profile);
    CarbonImmutable::setTestNow();

    expect($second->jobPosting->last_checked_at->isAfter($originalCheckedAt))->toBeTrue();
});

it('tier 2: updates the existing row (found via canonical identity) when the SAME job is rediscovered through a DIFFERENT provider, preserving original discovery provenance', function () {
    $profile = CareerProfile::factory()->create();
    $viaHimalayas = (new IngestDiscoveredCandidate)->ingest(
        discoveryCandidate(['source' => JobDiscoverySource::Himalayas, 'sourceJobId' => 'himalayas-1']),
        canonicalMatch(['canonicalSourceId' => 'gh-1']),
        $profile,
    );

    $viaAdzuna = (new IngestDiscoveredCandidate)->ingest(
        discoveryCandidate(['source' => JobDiscoverySource::Adzuna, 'sourceJobId' => 'adzuna-1']),
        canonicalMatch(['canonicalSourceId' => 'gh-1']),
        $profile,
    );

    expect($viaAdzuna->wasCreated)->toBeFalse()
        ->and($viaAdzuna->jobPosting->id)->toBe($viaHimalayas->jobPosting->id)
        // Discovery provenance is the ORIGINAL discoverer, never overwritten.
        ->and($viaAdzuna->jobPosting->discovery_source)->toBe(JobDiscoverySource::Himalayas)
        ->and($viaAdzuna->jobPosting->discovery_source_id)->toBe('himalayas-1')
        ->and(JobPosting::count())->toBe(1);
});

it('tier 2: never unsets canonical identity once resolved, even if a later rediscovery has no canonical match', function () {
    $profile = CareerProfile::factory()->create();
    $first = (new IngestDiscoveredCandidate)->ingest(discoveryCandidate(), canonicalMatch(), $profile);

    $second = (new IngestDiscoveredCandidate)->ingest(discoveryCandidate(), null, $profile);

    expect($second->jobPosting->id)->toBe($first->jobPosting->id)
        ->and($second->jobPosting->canonical_source)->toBe(JobCanonicalSource::Greenhouse)
        ->and($second->jobPosting->canonical_source_id)->toBe('gh-1');
});

it('a genuinely new canonical ID creates a distinct JobPosting rather than reopening the old one (repost semantics)', function () {
    $profile = CareerProfile::factory()->create();
    $first = (new IngestDiscoveredCandidate)->ingest(
        discoveryCandidate(['source' => JobDiscoverySource::Himalayas, 'sourceJobId' => 'himalayas-1', 'applicationUrl' => 'https://himalayas.app/jobs/original']),
        canonicalMatch(['canonicalSourceId' => 'gh-1', 'applicationUrl' => 'https://boards.greenhouse.io/acme/jobs/1']),
        $profile,
    );

    $repost = (new IngestDiscoveredCandidate)->ingest(
        discoveryCandidate(['source' => JobDiscoverySource::Himalayas, 'sourceJobId' => 'himalayas-2', 'applicationUrl' => 'https://himalayas.app/jobs/reposted']),
        canonicalMatch(['canonicalSourceId' => 'gh-2', 'applicationUrl' => 'https://boards.greenhouse.io/acme/jobs/2']),
        $profile,
    );

    expect($repost->wasCreated)->toBeTrue()
        ->and($repost->jobPosting->id)->not->toBe($first->jobPosting->id)
        ->and(JobPosting::count())->toBe(2);
});

it('tier 3: falls back to normalized application URL matching when neither identity matched', function () {
    $profile = CareerProfile::factory()->create();
    // First ingestion has no canonical match at all — establishes a
    // discovered row keyed only by (Himalayas, himalayas-1) with a
    // real source_url.
    $first = (new IngestDiscoveredCandidate)->ingest(
        discoveryCandidate(['source' => JobDiscoverySource::Himalayas, 'sourceJobId' => 'himalayas-1', 'applicationUrl' => 'https://boards.greenhouse.io/acme/jobs/1/']),
        null,
        $profile,
    );

    // A different provider discovers what happens to be the exact
    // same URL (different casing/trailing slash) with no canonical
    // match either — tier 1/2 can't catch this, tier 3 should.
    $second = (new IngestDiscoveredCandidate)->ingest(
        discoveryCandidate(['source' => JobDiscoverySource::Adzuna, 'sourceJobId' => 'adzuna-1', 'applicationUrl' => 'HTTPS://BOARDS.GREENHOUSE.IO/acme/jobs/1']),
        null,
        $profile,
    );

    expect($second->wasCreated)->toBeFalse()
        ->and($second->jobPosting->id)->toBe($first->jobPosting->id)
        ->and(JobPosting::count())->toBe(1);
});

it('never matches (tier 3 or otherwise) against a manually-created JobPosting, even with an identical URL', function () {
    $profile = CareerProfile::factory()->create();
    JobPosting::factory()->for($profile)->create(['source_url' => 'https://boards.greenhouse.io/acme/jobs/1']);

    $result = (new IngestDiscoveredCandidate)->ingest(
        discoveryCandidate(['applicationUrl' => 'https://boards.greenhouse.io/acme/jobs/1']),
        null,
        $profile,
    );

    expect($result->wasCreated)->toBeTrue()
        ->and(JobPosting::count())->toBe(2);
});

it('accepts a cross-provider duplicate surviving when neither identity nor URL can establish it is the same job (known Phase 1 limitation)', function () {
    $profile = CareerProfile::factory()->create();
    (new IngestDiscoveredCandidate)->ingest(
        discoveryCandidate(['source' => JobDiscoverySource::Himalayas, 'sourceJobId' => 'himalayas-1', 'applicationUrl' => 'https://himalayas.app/jobs/1']),
        null,
        $profile,
    );

    $second = (new IngestDiscoveredCandidate)->ingest(
        discoveryCandidate(['source' => JobDiscoverySource::Adzuna, 'sourceJobId' => 'adzuna-1', 'applicationUrl' => 'https://adzuna.com/jobs/1']),
        null,
        $profile,
    );

    expect($second->wasCreated)->toBeTrue()
        ->and(JobPosting::count())->toBe(2);
});

it('never rejects ingestion for a candidate with no description, defaulting to an empty string', function () {
    $profile = CareerProfile::factory()->create();

    $result = (new IngestDiscoveredCandidate)->ingest(discoveryCandidate(['description' => null]), null, $profile);

    expect($result->jobPosting->description)->toBe('');
});

it('preserves uncertain URL identity while normalizing only harmless differences', function (string $firstUrl, string $secondUrl, bool $same) {
    $profile = CareerProfile::factory()->create();
    $first = (new IngestDiscoveredCandidate)->ingest(discoveryCandidate(['applicationUrl' => $firstUrl]), null, $profile);
    $second = (new IngestDiscoveredCandidate)->ingest(discoveryCandidate(['sourceJobId' => 'other', 'applicationUrl' => $secondUrl]), null, $profile);
    expect($second->jobPosting->id === $first->jobPosting->id)->toBe($same)
        ->and(JobPosting::count())->toBe($same ? 1 : 2);
})->with([
    'host scheme port slash' => ['https://EXAMPLE.com:443/apply/', 'HTTPS://example.com/apply', true],
    'query IDs' => ['https://example.com/apply?job=1', 'https://example.com/apply?job=2', false],
    'path case' => ['https://example.com/Jobs/ABC', 'https://example.com/jobs/abc', false],
    'uncertain tracking' => ['https://example.com/apply?ref=1', 'https://example.com/apply?ref=2', false],
    'fragment routing' => ['https://example.com/#/jobs/1', 'https://example.com/#/jobs/2', false],
]);

it('rejects convergence between existing discovery and canonical owners without changing either', function () {
    $profile = CareerProfile::factory()->create();
    (new IngestDiscoveredCandidate)->ingest(discoveryCandidate(), null, $profile);
    (new IngestDiscoveredCandidate)->ingest(discoveryCandidate(['sourceJobId' => 'other']), canonicalMatch(), $profile);
    $before = JobPosting::orderBy('id')->get()->toArray();
    expect(fn () => (new IngestDiscoveredCandidate)->ingest(discoveryCandidate(), canonicalMatch(), $profile))
        ->toThrow(DiscoveryIngestionException::class);
    expect(JobPosting::orderBy('id')->get()->toArray())->toBe($before);
});

it('rejects a reused discovery ID with a different canonical identity without changing history', function () {
    $profile = CareerProfile::factory()->create();
    (new IngestDiscoveredCandidate)->ingest(discoveryCandidate(), canonicalMatch(), $profile);
    $before = JobPosting::first()->toArray();
    expect(fn () => (new IngestDiscoveredCandidate)->ingest(discoveryCandidate(), canonicalMatch(['canonicalSourceId' => 'gh-new']), $profile))
        ->toThrow(DiscoveryIngestionException::class);
    expect(JobPosting::first()->toArray())->toBe($before)->and(JobPosting::count())->toBe(1);
});

it('creates a new canonical repost despite a shared URL and leaves the closed old posting historical', function () {
    $profile = CareerProfile::factory()->create();
    $old = (new IngestDiscoveredCandidate)->ingest(discoveryCandidate(), canonicalMatch(), $profile)->jobPosting;
    $old->update(['status' => JobPostingLifecycleStatus::Closed]);
    $before = $old->fresh()->toArray();
    $new = (new IngestDiscoveredCandidate)->ingest(discoveryCandidate(['sourceJobId' => 'new']), canonicalMatch(['canonicalSourceId' => 'gh-new']), $profile);
    expect($new->wasCreated)->toBeTrue()->and($new->jobPosting->canonical_source_id)->toBe('gh-new')
        ->and($old->fresh()->toArray())->toBe($before)->and(JobPosting::count())->toBe(2);
});

it('preserves canonical content through missing enrichment and refreshes the same identity on later success', function () {
    $profile = CareerProfile::factory()->create();
    $candidate = discoveryCandidate(['sourceMetadata' => ['category' => 'original']]);
    $first = (new IngestDiscoveredCandidate)->ingest($candidate, canonicalMatch(['sourceMetadata' => ['department' => 'Engineering']]), $profile)->jobPosting;
    $authoritative = $first->only(['title', 'description', 'location', 'source_url', 'canonical_source', 'canonical_source_id', 'compensation_min', 'compensation_max']);
    $this->travel(1)->hours();
    $during = (new IngestDiscoveredCandidate)->ingest(discoveryCandidate(['title' => 'Aggregator title', 'description' => 'Truncated snippet', 'sourceMetadata' => ['category' => 'updated']]), null, $profile)->jobPosting;
    expect($during->only(array_keys($authoritative)))->toBe($authoritative)
        ->and($during->discovery_metadata['canonical'])->toBe(['department' => 'Engineering'])
        ->and($during->last_checked_at->isAfter($first->last_checked_at))->toBeTrue();
    $after = (new IngestDiscoveredCandidate)->ingest($candidate, canonicalMatch(['title' => 'Fresh canonical title', 'description' => 'Fresh full content', 'sourceMetadata' => ['department' => 'Platform']]), $profile)->jobPosting;
    expect($after->id)->toBe($first->id)->and($after->description)->toBe('Fresh full content')
        ->and($after->title)->toBe('Fresh canonical title')
        ->and($after->discovery_metadata['canonical'])->toBe(['department' => 'Platform'])
        ->and(JobPosting::count())->toBe(1);
});

it('keeps original provider metadata when another aggregator rediscovers the canonical job', function () {
    $profile = CareerProfile::factory()->create();
    $first = (new IngestDiscoveredCandidate)->ingest(discoveryCandidate(['sourceMetadata' => ['companySlug' => 'original']]), canonicalMatch(), $profile)->jobPosting;
    $second = (new IngestDiscoveredCandidate)->ingest(discoveryCandidate([
        'source' => JobDiscoverySource::Adzuna, 'sourceJobId' => 'adzuna-2', 'sourceMetadata' => ['category' => 'other-provider'],
    ]), canonicalMatch(), $profile)->jobPosting;
    expect($second->id)->toBe($first->id)->and($second->discovery_source)->toBe(JobDiscoverySource::Himalayas)
        ->and($second->discovery_metadata['discovery'])->toBe(['companySlug' => 'original'])
        ->and($second->discovered_at->equalTo($first->discovered_at))->toBeTrue();
});
