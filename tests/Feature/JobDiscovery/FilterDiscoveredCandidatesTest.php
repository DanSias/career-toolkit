<?php

use App\Enums\JobDiscoverySource;
use App\Enums\JobRemoteStatus;
use App\Support\JobDiscovery\DiscoveredJobCandidate;
use App\Support\JobDiscovery\DiscoverySearchCriteria;
use App\Support\JobDiscovery\FilterDiscoveredCandidates;
use Carbon\CarbonImmutable;

function candidate(array $overrides = []): DiscoveredJobCandidate
{
    return new DiscoveredJobCandidate(
        source: $overrides['source'] ?? JobDiscoverySource::Himalayas,
        sourceJobId: $overrides['sourceJobId'] ?? 'id-1',
        company: $overrides['company'] ?? 'Acme',
        title: $overrides['title'] ?? 'Senior Software Engineer',
        description: $overrides['description'] ?? 'Build backend APIs and data pipelines.',
        location: array_key_exists('location', $overrides) ? $overrides['location'] : 'United States',
        remoteStatus: array_key_exists('remoteStatus', $overrides) ? $overrides['remoteStatus'] : JobRemoteStatus::Remote,
        employmentType: array_key_exists('employmentType', $overrides) ? $overrides['employmentType'] : 'full_time',
        compensationMin: null,
        compensationMax: null,
        compensationCurrency: null,
        compensationInterval: null,
        applicationUrl: null,
        postedAt: null,
        sourceUpdatedAt: null,
        discoveredAt: CarbonImmutable::now(),
    );
}

function criteria(array $overrides = []): DiscoverySearchCriteria
{
    return new DiscoverySearchCriteria(
        keywords: $overrides['keywords'] ?? ['senior', 'software engineer'],
        excludedKeywords: $overrides['excludedKeywords'] ?? ['intern', 'junior'],
        remotePreference: $overrides['remotePreference'] ?? 'remote_us',
        allowedLocations: $overrides['allowedLocations'] ?? ['United States', 'US', 'Remote'],
        employmentType: array_key_exists('employmentType', $overrides) ? $overrides['employmentType'] : null,
    );
}

it('accepts a candidate matching a keyword', function () {
    expect((new FilterDiscoveredCandidates)->accepts(candidate(), criteria()))->toBeTrue();
});

it('rejects a candidate matching no keyword', function () {
    $c = candidate(['title' => 'Warehouse Associate', 'description' => 'Lift boxes.']);

    expect((new FilterDiscoveredCandidates)->accepts($c, criteria()))->toBeFalse();
});

it('rejects a candidate containing an excluded keyword even if it also matches a keyword', function () {
    $c = candidate(['title' => 'Junior Senior Software Engineer']);

    expect((new FilterDiscoveredCandidates)->accepts($c, criteria()))->toBeFalse();
});

it('accepts an explicitly Remote candidate under remote_us preference', function () {
    $c = candidate(['remoteStatus' => JobRemoteStatus::Remote, 'location' => 'Anywhere']);

    expect((new FilterDiscoveredCandidates)->accepts($c, criteria()))->toBeTrue();
});

it('rejects an explicitly Onsite candidate under remote_us preference', function () {
    $c = candidate(['remoteStatus' => JobRemoteStatus::Onsite, 'location' => 'United States']);

    expect((new FilterDiscoveredCandidates)->accepts($c, criteria()))->toBeFalse();
});

it('rejects an explicitly Hybrid candidate under remote_us preference', function () {
    $c = candidate(['remoteStatus' => JobRemoteStatus::Hybrid]);

    expect((new FilterDiscoveredCandidates)->accepts($c, criteria()))->toBeFalse();
});

it('falls back to allowed-location text matching when remote_status is unknown', function () {
    $c = candidate(['remoteStatus' => null, 'location' => 'Remote, US']);

    expect((new FilterDiscoveredCandidates)->accepts($c, criteria()))->toBeTrue();
});

it('rejects when remote_status is unknown and location matches no allowed location', function () {
    $c = candidate(['remoteStatus' => null, 'location' => 'Berlin, Germany']);

    expect((new FilterDiscoveredCandidates)->accepts($c, criteria()))->toBeFalse();
});

it('does not reject for missing location/remote metadata when both are entirely absent (conservative default)', function () {
    $c = candidate(['remoteStatus' => null, 'location' => null]);

    expect((new FilterDiscoveredCandidates)->accepts($c, criteria()))->toBeTrue();
});

it('accepts any remote status when remote_preference is any', function () {
    $c = candidate(['remoteStatus' => JobRemoteStatus::Onsite, 'location' => 'Berlin, Germany']);

    expect((new FilterDiscoveredCandidates)->accepts($c, criteria(['remotePreference' => 'any'])))->toBeTrue();
});

it('enforces employment_type only when both criteria and candidate specify one', function () {
    $c = candidate(['employmentType' => 'contract']);

    expect((new FilterDiscoveredCandidates)->accepts($c, criteria(['employmentType' => 'full_time'])))->toBeFalse()
        ->and((new FilterDiscoveredCandidates)->accepts($c, criteria(['employmentType' => 'contract'])))->toBeTrue()
        ->and((new FilterDiscoveredCandidates)->accepts($c, criteria(['employmentType' => null])))->toBeTrue();
});

it('does not reject for a missing candidate employment_type even when criteria specifies one', function () {
    $c = candidate(['employmentType' => null]);

    expect((new FilterDiscoveredCandidates)->accepts($c, criteria(['employmentType' => 'full_time'])))->toBeTrue();
});
