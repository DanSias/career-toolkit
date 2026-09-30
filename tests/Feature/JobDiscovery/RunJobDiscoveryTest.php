<?php

use App\Enums\DiscoveryStatus;
use App\Enums\JobCanonicalSource;
use App\Enums\JobDiscoverySource;
use App\Models\CareerProfile;
use App\Models\DiscoveryProviderAttempt;
use App\Models\DiscoveryRun;
use App\Models\JobPosting;
use App\Models\KnownAtsBoard;
use App\Support\JobDiscovery\RunJobDiscovery;
use Illuminate\Support\Facades\DB;
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

function jobicyJobFixture(int $id, string $title = 'Software Engineer', string $company = 'Acme'): array
{
    return [
        'id' => $id,
        'jobTitle' => $title,
        'companyName' => $company,
        'jobDescription' => '<p>Build things.</p>',
        'jobGeo' => 'USA',
        'jobType' => ['Full-Time'],
        'url' => "https://jobicy.com/jobs/{$id}",
        'pubDate' => '2026-09-01T12:00:00+00:00',
    ];
}

it('ingests candidates from all three providers when all succeed', function () {
    Http::fake([
        'himalayas.app/*' => Http::response(['jobs' => [himalayasJobFixture('1')]]),
        'api.adzuna.com/*' => Http::response(['results' => [adzunaJobFixture('a1')]]),
        'jobicy.com/*' => Http::response(['jobs' => [jobicyJobFixture(1)]]),
    ]);

    $discoveryRun = (new RunJobDiscovery)->run();

    expect($discoveryRun->status)->toBe(DiscoveryStatus::Succeeded)
        ->and($discoveryRun->providerAttempts)->toHaveCount(3)
        ->and(JobPosting::count())->toBe(3);

    $himalayasAttempt = $discoveryRun->providerAttempts->firstWhere('provider', JobDiscoverySource::Himalayas);
    expect($himalayasAttempt->status)->toBe(DiscoveryStatus::Succeeded)
        ->and($himalayasAttempt->candidates_retrieved)->toBe(1)
        ->and($himalayasAttempt->candidates_accepted)->toBe(1)
        ->and($himalayasAttempt->jobs_created)->toBe(1);

    $jobicyAttempt = $discoveryRun->providerAttempts->firstWhere('provider', JobDiscoverySource::Jobicy);
    expect($jobicyAttempt->status)->toBe(DiscoveryStatus::Succeeded)
        ->and($jobicyAttempt->candidates_retrieved)->toBe(1)
        ->and($jobicyAttempt->candidates_accepted)->toBe(1)
        ->and($jobicyAttempt->jobs_created)->toBe(1);
});

it('isolates one provider failing from the others succeeding', function () {
    Http::fake([
        'himalayas.app/*' => Http::response(['jobs' => [himalayasJobFixture('1')]]),
        'api.adzuna.com/*' => Http::response(null, 500),
        'jobicy.com/*' => Http::response(['jobs' => []]),
    ]);

    $discoveryRun = (new RunJobDiscovery)->run();

    // A partial run distinguishes this from full success.
    expect($discoveryRun->status)->toBe(DiscoveryStatus::Partial);

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

it('isolates Jobicy failing from the other providers succeeding', function () {
    Http::fake([
        'himalayas.app/*' => Http::response(['jobs' => [himalayasJobFixture('1')]]),
        'api.adzuna.com/*' => Http::response(['results' => []]),
        'jobicy.com/*' => Http::response(null, 500),
    ]);

    $discoveryRun = (new RunJobDiscovery)->run();

    expect($discoveryRun->status)->toBe(DiscoveryStatus::Partial);

    $jobicyAttempt = $discoveryRun->providerAttempts->firstWhere('provider', JobDiscoverySource::Jobicy);
    expect($jobicyAttempt->status)->toBe(DiscoveryStatus::Failed)
        ->and($jobicyAttempt->failure_message)->not->toBeNull();

    // Himalayas's successful candidate was still ingested despite Jobicy's failure.
    expect(JobPosting::count())->toBe(1);
});

it('records accurate counts including filtered-out candidates', function () {
    Http::fake([
        'himalayas.app/*' => Http::response(['jobs' => [
            himalayasJobFixture('1', 'Software Engineer'),
            himalayasJobFixture('2', 'Warehouse Associate'),
        ]]),
        'api.adzuna.com/*' => Http::response(['results' => []]),
        'jobicy.com/*' => Http::response(['jobs' => []]),
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
        'jobicy.com/*' => Http::response(['jobs' => []]),
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
        'jobicy.com/*' => Http::response(['jobs' => []]),
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
        'jobicy.com/*' => Http::response(['jobs' => []]),
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
        'jobicy.com/*' => Http::response(['jobs' => []]),
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
        'jobicy.com/*' => Http::response(['jobs' => []]),
    ]);
    (new RunJobDiscovery)->run();
    expect(JobPosting::count())->toBe(1);

    Http::fake([
        'himalayas.app/*' => Http::response(null, 500),
        'api.adzuna.com/*' => Http::response(null, 500),
        'jobicy.com/*' => Http::response(null, 500),
    ]);
    (new RunJobDiscovery)->run();

    expect(JobPosting::count())->toBe(1)
        ->and(JobPosting::first()->title)->not->toBeEmpty();
});

it('settles every attempt and distinguishes partial from total provider failure', function (bool $himalayasFails, bool $adzunaFails, bool $jobicyFails, DiscoveryStatus $status) {
    Http::fake([
        'himalayas.app/*' => $himalayasFails ? Http::response(null, 500) : Http::response(['jobs' => [himalayasJobFixture('1')]]),
        'api.adzuna.com/*' => $adzunaFails ? Http::response(null, 500) : Http::response(['results' => [adzunaJobFixture('a1')]]),
        'jobicy.com/*' => $jobicyFails ? Http::response(null, 500) : Http::response(['jobs' => []]),
    ]);
    $run = (new RunJobDiscovery)->run();
    expect($run->status)->toBe($status)->and($run->finished_at)->not->toBeNull();
    foreach ($run->providerAttempts as $attempt) {
        expect($attempt->finished_at)->not->toBeNull()->and($attempt->status)->toBeIn([DiscoveryStatus::Succeeded, DiscoveryStatus::Failed]);
    }
})->with([
    [false, true, false, DiscoveryStatus::Partial],
    [true, false, false, DiscoveryStatus::Partial],
    [true, true, false, DiscoveryStatus::Partial],
    [true, true, true, DiscoveryStatus::Failed],
]);

it('settles an unexpected orchestration failure and preserves earlier successful writes and counts', function () {
    KnownAtsBoard::factory()->create(['company_name' => 'broken-company', 'ats_type' => 'greenhouse']);
    DB::table('known_ats_boards')->where('company_name', 'broken-company')->update(['ats_type' => 'invalid']);
    Http::fake([
        'himalayas.app/*' => Http::response(['jobs' => [himalayasJobFixture('1'), himalayasJobFixture('2', 'Software Engineer', 'broken-company')]]),
        'api.adzuna.com/*' => Http::response(['results' => [adzunaJobFixture('a1')]]),
        'jobicy.com/*' => Http::response(['jobs' => []]),
    ]);
    $run = (new RunJobDiscovery)->run();
    $attempt = $run->providerAttempts->firstWhere('provider', JobDiscoverySource::Himalayas);
    expect($run->status)->toBe(DiscoveryStatus::Partial)->and($run->finished_at)->not->toBeNull()
        ->and($attempt->status)->toBe(DiscoveryStatus::Failed)->and($attempt->finished_at)->not->toBeNull()
        ->and($attempt->failure_category)->toBe('unexpected_error')->and($attempt->jobs_created)->toBe(1)
        ->and(JobPosting::count())->toBe(2);
});

it('settles malformed individual entries through provider failure isolation', function () {
    Http::fake([
        'himalayas.app/*' => Http::response(['jobs' => [null]]),
        'api.adzuna.com/*' => Http::response(['results' => [adzunaJobFixture('a1')]]),
        'jobicy.com/*' => Http::response(['jobs' => []]),
    ]);
    $run = (new RunJobDiscovery)->run();
    $attempt = $run->providerAttempts->firstWhere('provider', JobDiscoverySource::Himalayas);
    expect($run->status)->toBe(DiscoveryStatus::Partial)->and($attempt->status)->toBe(DiscoveryStatus::Failed)
        ->and($attempt->failure_category)->toBe('provider_error')->and($attempt->finished_at)->not->toBeNull()
        ->and(JobPosting::count())->toBe(1);
});

it('reports identity conflicts safely while continuing other candidates', function () {
    $profile = CareerProfile::first();
    $old = JobPosting::factory()->for($profile)->discovered(JobDiscoverySource::Himalayas)->create([
        'discovery_source_id' => 'https://himalayas.app/jobs/1',
        'canonical_source' => JobCanonicalSource::Greenhouse, 'canonical_source_id' => 'old',
    ]);
    $before = $old->fresh()->toArray();
    KnownAtsBoard::factory()->create(['company_name' => 'acme', 'ats_type' => 'greenhouse', 'board_identifier' => 'acme']);
    Http::fake([
        'himalayas.app/*' => Http::response(['jobs' => [himalayasJobFixture('1'), himalayasJobFixture('2', 'Software Engineer', 'Unknown')]]),
        'boards-api.greenhouse.io/*' => Http::response(['jobs' => [['id' => 99, 'title' => 'Software Engineer']]]),
        'api.adzuna.com/*' => Http::response(['results' => []]),
        'jobicy.com/*' => Http::response(['jobs' => []]),
    ]);
    $run = (new RunJobDiscovery)->run();
    $attempt = $run->providerAttempts->firstWhere('provider', JobDiscoverySource::Himalayas);
    expect($run->status)->toBe(DiscoveryStatus::Partial)->and($attempt->failure_category)->toBe('identity_conflict')
        ->and($attempt->status)->toBe(DiscoveryStatus::Failed)->and($attempt->jobs_created)->toBe(1)
        ->and($old->fresh()->toArray())->toBe($before)->and(JobPosting::count())->toBe(2);
});

it('settles a run and its running attempt if orchestration fails outside candidate processing', function () {
    Http::fake(['himalayas.app/*' => Http::response(['jobs' => []])]);
    DiscoveryProviderAttempt::updating(function () {
        throw new RuntimeException('Simulated attempt finalization exception');
    });
    try {
        $run = (new RunJobDiscovery)->run();
        expect($run->status)->toBe(DiscoveryStatus::Failed)->and($run->finished_at)->not->toBeNull()
            ->and($run->providerAttempts)->toHaveCount(1)
            ->and($run->providerAttempts->first()->status)->toBe(DiscoveryStatus::Failed)
            ->and($run->providerAttempts->first()->finished_at)->not->toBeNull();
    } finally {
        DiscoveryProviderAttempt::flushEventListeners();
    }
});
