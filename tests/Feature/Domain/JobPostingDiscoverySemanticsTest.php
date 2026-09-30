<?php

use App\Enums\JobCanonicalSource;
use App\Enums\JobDiscoverySource;
use App\Enums\JobPostingLifecycleStatus;
use App\Models\JobPosting;
use Illuminate\Database\UniqueConstraintViolationException;

it('defaults a hand-created JobPosting to discovery_source manual', function () {
    $job = JobPosting::factory()->create();

    expect($job->discovery_source)->toBe(JobDiscoverySource::Manual)
        ->and($job->isDiscovered())->toBeFalse();
});

it('reports isDiscovered true for a non-manual discovery_source, correctly, even on a freshly-created unrefreshed instance', function () {
    // Guards against the exact bug the creating-hook comment warns
    // about: a DB-level-only default would leave this attribute unset
    // on the in-memory instance immediately after create().
    $job = JobPosting::factory()->discovered(JobDiscoverySource::Himalayas)->create();

    expect($job->discovery_source)->toBe(JobDiscoverySource::Himalayas)
        ->and($job->isDiscovered())->toBeTrue();
});

it('sets discovered_at and status defaults on create without any explicit code changes at the call site', function () {
    $job = JobPosting::factory()->create();

    expect($job->discovered_at)->not->toBeNull()
        ->and($job->status)->toBe(JobPostingLifecycleStatus::Open);
});

it('never populates canonical_source for a manual posting', function () {
    $job = JobPosting::factory()->create();

    expect($job->canonical_source)->toBeNull()
        ->and($job->canonical_source_id)->toBeNull();
});

it('allows multiple manual JobPostings to coexist despite sharing discovery_source manual and a null discovery_source_id', function () {
    JobPosting::factory()->count(3)->create();

    expect(JobPosting::count())->toBe(3);
});

it('rejects a second JobPosting with the same (discovery_source, discovery_source_id)', function () {
    JobPosting::factory()->discovered(JobDiscoverySource::Himalayas)->create(['discovery_source_id' => 'himalayas-123']);

    expect(fn () => JobPosting::factory()->discovered(JobDiscoverySource::Himalayas)->create(['discovery_source_id' => 'himalayas-123']))
        ->toThrow(UniqueConstraintViolationException::class);
});

it('allows the same discovery_source_id under different discovery sources', function () {
    JobPosting::factory()->discovered(JobDiscoverySource::Himalayas)->create(['discovery_source_id' => 'shared-id']);
    JobPosting::factory()->discovered(JobDiscoverySource::Adzuna)->create(['discovery_source_id' => 'shared-id']);

    expect(JobPosting::count())->toBe(2);
});

it('rejects a second JobPosting with the same (canonical_source, canonical_source_id)', function () {
    JobPosting::factory()->discovered(JobDiscoverySource::Himalayas)->create([
        'canonical_source' => JobCanonicalSource::Greenhouse,
        'canonical_source_id' => 'gh-123',
    ]);

    expect(fn () => JobPosting::factory()->discovered(JobDiscoverySource::Adzuna)->create([
        'canonical_source' => JobCanonicalSource::Greenhouse,
        'canonical_source_id' => 'gh-123',
    ]))->toThrow(UniqueConstraintViolationException::class);
});

it('allows many JobPostings with a null canonical_source, since none are canonically enriched yet', function () {
    JobPosting::factory()->discovered(JobDiscoverySource::Himalayas)->count(3)->create();

    expect(JobPosting::count())->toBe(3);
});
