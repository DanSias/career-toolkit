<?php

use App\Enums\DescriptionCompleteness;
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
        descriptionCompleteness: $overrides['descriptionCompleteness'] ?? DescriptionCompleteness::Complete,
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
        sourceMetadata: $overrides['sourceMetadata'] ?? [],
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

it('accepts a matching candidate when excludedKeywords is empty (regression: empty list must mean "excludes nothing", not "excludes everything")', function () {
    $c = candidate();

    expect((new FilterDiscoveredCandidates)->accepts($c, criteria(['excludedKeywords' => []])))->toBeTrue();
});

it('accepts any candidate when keywords is empty (no positive filter configured)', function () {
    $c = candidate(['title' => 'Warehouse Associate', 'description' => 'Lift boxes.']);

    expect((new FilterDiscoveredCandidates)->accepts($c, criteria(['keywords' => []])))->toBeTrue();
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

it('checks structured remote country restrictions independently of workplace type', function (?array $restrictions, bool $accepted) {
    $job = candidate(['sourceMetadata' => ['locationRestrictions' => $restrictions]]);
    expect((new FilterDiscoveredCandidates)->accepts($job, criteria()))->toBe($accepted);
})->with([
    'South Korea only' => [['South Korea'], false],
    'United Kingdom only' => [['United Kingdom'], false],
    'United States' => [['United States'], true],
    'multiple including US' => [['United Kingdom', 'United States'], true],
    'unknown' => [null, true],
    'unrestricted' => [[], true],
]);

it('matches whole words and phrases rather than substrings', function (string $title, string $description, array $keywords, bool $accepted) {
    expect((new FilterDiscoveredCandidates)->accepts(candidate(['title' => $title, 'description' => $description]), criteria(['keywords' => $keywords])))
        ->toBe($accepted);
})->with([
    'internal is not intern' => ['Software Engineer', 'Build internal tools', ['software engineer'], true],
    'intern excluded' => ['Software Engineer Intern', 'Build tools', ['software engineer'], false],
    'senior word' => ['Senior Software Engineer', '', ['senior'], true],
    'staff word' => ['Staff Backend Engineer', '', ['staff'], true],
    'seniority not senior' => ['Seniority Analyst', '', ['senior'], false],
    'phrase whitespace' => ['Senior Software   Engineer', '', ['software engineer'], true],
    'backend role' => ['Staff Backend Engineer', '', ['backend'], true],
    'role only mentioned in description' => ['CEO Office – AI Neobank App', 'Work with senior staff and software engineer teams building backend APIs.', ['software engineer', 'backend'], false],
]);

it('does not treat seniority alone as a target role in the default configuration', function () {
    expect((new FilterDiscoveredCandidates)->accepts(candidate(['title' => 'Senior Account Executive']), DiscoverySearchCriteria::fromConfig()))->toBeFalse();
});

it('rejects the observed CEO Office posting whose description mentions senior leadership', function () {
    // Exact title and relevant excerpt from the preserved first live run.
    $job = candidate([
        'title' => 'CEO Office - AI Neobank App',
        'description' => '<li><p>Able to work directly with senior leadership and cross-functional teams.</p></li>',
        'sourceMetadata' => ['locationRestrictions' => ['United Kingdom']],
    ]);
    // Test role matching independently of its also-ineligible geography.
    config(['job_discovery.search.remote_preference' => 'any']);
    expect((new FilterDiscoveredCandidates)->accepts($job, DiscoverySearchCriteria::fromConfig()))->toBeFalse();
});
