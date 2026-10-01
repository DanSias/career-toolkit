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
        excludedTitleKeywords: $overrides['excludedTitleKeywords'] ?? [],
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

it('rejects titles that clearly identify SRE / Site Reliability / primarily DevOps role identities', function (string $title) {
    $c = candidate(['title' => $title]);

    expect((new FilterDiscoveredCandidates)->accepts($c, criteria(['excludedTitleKeywords' => ['site reliability', 'sre', 'devops']])))
        ->toBeFalse();
})->with([
    'the observed live false positive' => ['Principal Software Engineer, DevOps'],
    'senior site reliability' => ['Senior Site Reliability Engineer'],
    'site reliability' => ['Site Reliability Engineer'],
    'senior devops' => ['Senior DevOps Engineer'],
]);

it('keeps accepting ordinary software-engineering titles under the same exclusions', function (string $title) {
    $c = candidate(['title' => $title]);

    $criteria = criteria([
        'keywords' => ['senior', 'staff', 'software engineer', 'full stack'],
        'excludedTitleKeywords' => ['site reliability', 'sre', 'devops'],
    ]);

    expect((new FilterDiscoveredCandidates)->accepts($c, $criteria))->toBeTrue();
})->with([
    'fullstack' => ['Senior Software Engineer, Fullstack'],
    'backend' => ['Senior Software Engineer, Backend'],
    'staff' => ['Staff Software Engineer'],
    'full stack developer' => ['Full Stack Developer'],
]);

it('matches title-identity exclusions case-insensitively regardless of how each side is cased', function (string $title, string $keyword) {
    $c = candidate(['title' => $title]);

    expect((new FilterDiscoveredCandidates)->accepts($c, criteria(['excludedTitleKeywords' => [$keyword]])))
        ->toBeFalse();
})->with([
    'uppercase phrase in the title' => ['Senior SITE RELIABILITY Engineer', 'site reliability'],
    'camelcase title, mixed-case keyword' => ['Principal Software Engineer, DeVoPs', 'dEvOpS'],
    'abbreviation title, mixed-case keyword' => ['Senior SRE', 'sRe'],
]);

it('does not reject a relevant title merely because the DESCRIPTION mentions DevOps / SRE / infrastructure terminology', function (string $description) {
    $c = candidate(['title' => 'Senior Backend Software Engineer', 'description' => $description]);

    // Title-only exclusions are configured; the excluded words appear in
    // the description, so this only passes if the check never inspects
    // description text.
    expect((new FilterDiscoveredCandidates)->accepts($c, criteria(['excludedTitleKeywords' => ['site reliability', 'sre', 'devops']])))
        ->toBeTrue();
})->with([
    'day-to-day tooling' => ['Work with the DevOps and SRE teams; Kubernetes, Terraform and AWS in everyday use.'],
    'production support' => ['Occasionally rotate on site reliability on-call and support production infrastructure.'],
    'no mention at all' => ['Build backend APIs and data pipelines.'],
]);

it('accepts an SRE-identified title when excludedTitleKeywords is empty (regression: an empty list excludes nothing, not everything)', function () {
    $c = candidate(['title' => 'Senior Site Reliability Engineer']);

    expect((new FilterDiscoveredCandidates)->accepts($c, criteria(['excludedTitleKeywords' => []])))->toBeTrue();
});

it('rejects the observed DevOps-titled posting under the actual repository configuration', function () {
    // Exact title from the preserved live validation that downstream
    // analysis later rated no_evidence on 30 of 47 findings.
    $job = candidate([
        'title' => 'Principal Software Engineer, DevOps',
        'description' => 'Own AWS, Kubernetes, Terraform, and on-call production infrastructure.',
    ]);

    config(['job_discovery.search.remote_preference' => 'any']);
    expect((new FilterDiscoveredCandidates)->accepts($job, DiscoverySearchCriteria::fromConfig()))->toBeFalse();
});

it('still accepts an ordinary full-stack title whose description mentions infrastructure, under the actual repository configuration', function () {
    $job = candidate([
        'title' => 'Senior Software Engineer, Fullstack',
        'description' => 'Work with the DevOps and site reliability teams on Kubernetes, Terraform and AWS infrastructure.',
    ]);

    config(['job_discovery.search.remote_preference' => 'any']);
    expect((new FilterDiscoveredCandidates)->accepts($job, DiscoverySearchCriteria::fromConfig()))->toBeTrue();
});
