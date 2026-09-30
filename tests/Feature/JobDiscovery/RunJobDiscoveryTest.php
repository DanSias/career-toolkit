<?php

use App\Enums\DiscoveryStatus;
use App\Enums\JobCanonicalSource;
use App\Enums\JobDiscoverySource;
use App\Models\CareerProfile;
use App\Models\DiscoveryRun;
use App\Models\JobPosting;
use App\Models\KnownAtsBoard;
use App\Support\JobDiscovery\RunJobDiscovery;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

beforeEach(function () {
    Sleep::fake();
    CareerProfile::factory()->create();
    config([
        'job_discovery.search.keywords' => ['software engineer'],
        'job_discovery.search.excluded_keywords' => [],
        'job_discovery.search.remote_preference' => 'any',
        'job_discovery.search.allowed_locations' => [],
        'job_discovery.search.employment_type' => null,
        'services.adzuna.app_id' => 'test-id',
        'services.adzuna.app_key' => 'test-key',
        'services.adzuna.country' => 'us',
    ]);
});

function himalayasJobFixture(string $id, string $title = 'Software Engineer', string $company = 'Acme'): array
{
    return [
        'title' => $title,
        'companyName' => $company,
        'description' => 'Build things.',
        'locationRestrictions' => ['United States'],
        'applicationLink' => "https://himalayas.app/jobs/{$id}",
        'guid' => "https://himalayas.app/jobs/{$id}",
    ];
}

function adzunaJobFixture(string $id, string $title = 'Software Engineer', string $company = 'Acme'): array
{
    return [
        'id' => $id,
        'title' => $title,
        'description' => 'Build things.',
        'company' => ['display_name' => $company],
        'location' => ['display_name' => 'Remote'],
        'redirect_url' => "https://www.adzuna.com/land/ad/{$id}",
        'created' => '2026-09-01T12:00:00Z',
    ];
}

it('ingests candidates from both providers when both succeed', function () {
    Http::fake([
        'himalayas.app/*' => Http::response(['jobs' => [himalayasJobFixture('1')]]),
        'api.adzuna.com/*' => Http::response(['results' => [adzunaJobFixture('a1')]]),
    ]);

    $discoveryRun = (new RunJobDiscovery)->run();

    expect($discoveryRun->status)->toBe(DiscoveryStatus::Succeeded)
        ->and($discoveryRun->providerAttempts)->toHaveCount(2)
        ->and(JobPosting::count())->toBe(2);

    $himalayasAttempt = $discoveryRun->providerAttempts->firstWhere('provider', JobDiscoverySource::Himalayas);
    expect($himalayasAttempt->status)->toBe(DiscoveryStatus::Succeeded)
        ->and($himalayasAttempt->candidates_retrieved)->toBe(1)
        ->and($himalayasAttempt->candidates_accepted)->toBe(1)
        ->and($himalayasAttempt->jobs_created)->toBe(1);
});

it('isolates one provider failing from the other succeeding', function () {
    Http::fake([
        'himalayas.app/*' => Http::response(['jobs' => [himalayasJobFixture('1')]]),
        'api.adzuna.com/*' => Http::response(null, 500),
    ]);

    $discoveryRun = (new RunJobDiscovery)->run();

    // The RUN itself still finishes, even though one provider failed.
    expect($discoveryRun->status)->toBe(DiscoveryStatus::Succeeded);

    $himalayasAttempt = $discoveryRun->providerAttempts->firstWhere('provider', JobDiscoverySource::Himalayas);
    $adzunaAttempt = $discoveryRun->providerAttempts->firstWhere('provider', JobDiscoverySource::Adzuna);

    expect($himalayasAttempt->status)->toBe(DiscoveryStatus::Succeeded)
        ->and($himalayasAttempt->jobs_created)->toBe(1)
        ->and($adzunaAttempt->status)->toBe(DiscoveryStatus::Failed)
        ->and($adzunaAttempt->failure_message)->not->toBeNull()
        // Failure info is useful but safe — no raw payload/stack trace leaked.
        ->and($adzunaAttempt->failure_message)->not->toContain('Stack trace');

    // Himalayas's successful candidate was still ingested despite Adzuna's failure.
    expect(JobPosting::count())->toBe(1);
});

it('records accurate counts including filtered-out candidates', function () {
    Http::fake([
        'himalayas.app/*' => Http::response(['jobs' => [
            himalayasJobFixture('1', 'Software Engineer'),
            himalayasJobFixture('2', 'Warehouse Associate'),
        ]]),
        'api.adzuna.com/*' => Http::response(['results' => []]),
    ]);

    $discoveryRun = (new RunJobDiscovery)->run();

    $himalayasAttempt = $discoveryRun->providerAttempts->firstWhere('provider', JobDiscoverySource::Himalayas);
    expect($himalayasAttempt->candidates_retrieved)->toBe(2)
        ->and($himalayasAttempt->candidates_accepted)->toBe(1)
        ->and($himalayasAttempt->jobs_created)->toBe(1);
});

it('canonically enriches a candidate whose company resolves to a known ATS board', function () {
    KnownAtsBoard::factory()->create([
        'company_name' => 'acme',
        'ats_type' => 'greenhouse',
        'board_identifier' => 'acme-board',
    ]);

    Http::fake([
        'himalayas.app/*' => Http::response(['jobs' => [himalayasJobFixture('1', 'Software Engineer', 'Acme')]]),
        'api.adzuna.com/*' => Http::response(['results' => []]),
        'boards-api.greenhouse.io/*' => Http::response(['jobs' => [[
            'id' => 999,
            'title' => 'Software Engineer',
            'content' => 'Canonical description.',
            'location' => ['name' => 'New York, NY'],
            'absolute_url' => 'https://job-boards.greenhouse.io/acme/jobs/999',
            'updated_at' => '2026-09-01T00:00:00Z',
        ]]]),
    ]);

    $discoveryRun = (new RunJobDiscovery)->run();

    $himalayasAttempt = $discoveryRun->providerAttempts->firstWhere('provider', JobDiscoverySource::Himalayas);
    expect($himalayasAttempt->jobs_canonicalized)->toBe(1);

    $job = JobPosting::first();
    expect($job->canonical_source)->toBe(JobCanonicalSource::Greenhouse)
        ->and($job->description)->toBe('Canonical description.');
});

it('still ingests a candidate whose company does not resolve to any known ATS board', function () {
    Http::fake([
        'himalayas.app/*' => Http::response(['jobs' => [himalayasJobFixture('1', 'Software Engineer', 'Totally Unknown Co')]]),
        'api.adzuna.com/*' => Http::response(['results' => []]),
    ]);

    $discoveryRun = (new RunJobDiscovery)->run();

    expect(JobPosting::count())->toBe(1)
        ->and(JobPosting::first()->canonical_source)->toBeNull();
});

it('a canonical adapter failing does not block ingestion — the candidate still proceeds aggregator-only', function () {
    KnownAtsBoard::factory()->create(['company_name' => 'acme', 'ats_type' => 'greenhouse', 'board_identifier' => 'acme-board']);

    Http::fake([
        'himalayas.app/*' => Http::response(['jobs' => [himalayasJobFixture('1', 'Software Engineer', 'Acme')]]),
        'api.adzuna.com/*' => Http::response(['results' => []]),
        'boards-api.greenhouse.io/*' => Http::response(null, 500),
    ]);

    $discoveryRun = (new RunJobDiscovery)->run();

    expect(JobPosting::count())->toBe(1)
        ->and(JobPosting::first()->canonical_source)->toBeNull();
});

it('rerunning the pipeline is safe — no duplicates, existing rows just get updated', function () {
    Http::fake([
        'himalayas.app/*' => Http::response(['jobs' => [himalayasJobFixture('1')]]),
        'api.adzuna.com/*' => Http::response(['results' => []]),
    ]);

    (new RunJobDiscovery)->run();
    (new RunJobDiscovery)->run();

    expect(JobPosting::count())->toBe(1)
        ->and(DiscoveryRun::count())->toBe(2);
});

it('a failed run does not corrupt JobPostings created by a prior successful run', function () {
    Http::fake([
        'himalayas.app/*' => Http::response(['jobs' => [himalayasJobFixture('1')]]),
        'api.adzuna.com/*' => Http::response(['results' => []]),
    ]);
    (new RunJobDiscovery)->run();
    expect(JobPosting::count())->toBe(1);

    Http::fake([
        'himalayas.app/*' => Http::response(null, 500),
        'api.adzuna.com/*' => Http::response(null, 500),
    ]);
    (new RunJobDiscovery)->run();

    expect(JobPosting::count())->toBe(1)
        ->and(JobPosting::first()->title)->not->toBeEmpty();
});
