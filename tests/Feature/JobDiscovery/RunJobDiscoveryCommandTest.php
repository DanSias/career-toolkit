<?php

use App\Enums\DiscoveryStatus;
use App\Models\CareerProfile;
use App\Models\DiscoveryRun;
use App\Models\JobPosting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

beforeEach(function () {
    Sleep::fake();
    CareerProfile::factory()->create();
    config([
        'services.adzuna.app_id' => 'test-id',
        'services.adzuna.app_key' => 'test-key',
        'services.adzuna.country' => 'us',
        'job_discovery.search.keywords' => ['software engineer'],
        'job_discovery.search.excluded_keywords' => [],
        'job_discovery.search.remote_preference' => 'any',
        'job_discovery.search.allowed_locations' => [],
        'job_discovery.search.employment_type' => null,
    ]);
});

it('runs the discovery pipeline and reports a summary', function () {
    Http::fake([
        'himalayas.app/*' => Http::response(['jobs' => [[
            'title' => 'Software Engineer',
            'companyName' => 'Acme',
            'description' => 'Build things.',
            'applicationLink' => 'https://himalayas.app/jobs/1',
            'guid' => 'https://himalayas.app/jobs/1',
        ]]]),
        'api.adzuna.com/*' => Http::response(['results' => []]),
    ]);

    $this->artisan('discovery:run')
        ->expectsOutputToContain('Discovery run')
        ->assertSuccessful();

    expect(JobPosting::count())->toBe(1);
});

it('exits successfully even when a provider fails, since the run still completes', function () {
    Http::fake([
        'himalayas.app/*' => Http::response(null, 500),
        'api.adzuna.com/*' => Http::response(['results' => []]),
    ]);

    $this->artisan('discovery:run')->assertSuccessful();
});

it('returns failure when both providers fail and persists total failure', function () {
    Http::fake(['*' => Http::response(null, 500)]);
    $this->artisan('discovery:run')->assertFailed();
    expect(DiscoveryRun::first()->status)->toBe(DiscoveryStatus::Failed);
});
