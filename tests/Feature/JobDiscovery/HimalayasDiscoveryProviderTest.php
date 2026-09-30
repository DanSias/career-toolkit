<?php

use App\Enums\JobDiscoverySource;
use App\Enums\JobRemoteStatus;
use App\Support\JobDiscovery\Providers\DiscoveryProviderException;
use App\Support\JobDiscovery\Providers\HimalayasDiscoveryProvider;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

beforeEach(function () {
    Sleep::fake();
    config(['services.himalayas.base_url' => 'https://himalayas.test/jobs/api']);
});

function fakeHimalayasJob(array $overrides = []): array
{
    return array_merge([
        'title' => 'Senior Backend Engineer',
        'companyName' => 'Acme Corp',
        'companySlug' => 'acme',
        'description' => 'Build backend systems.',
        'employmentType' => 'Full Time',
        'minSalary' => 150000,
        'maxSalary' => 190000,
        'salaryPeriod' => 'annual',
        'currency' => 'USD',
        'seniority' => ['Senior'],
        'locationRestrictions' => ['United States'],
        'categories' => ['Software-Engineer'],
        'pubDate' => 1790000000,
        'expiryDate' => 1795000000,
        'applicationLink' => 'https://himalayas.app/companies/acme/jobs/senior-backend-engineer-123',
        'guid' => 'https://himalayas.app/companies/acme/jobs/senior-backend-engineer-123',
    ], $overrides);
}

it('retrieves and normalizes a successful response', function () {
    Http::fake(['himalayas.test/*' => Http::response([
        'jobs' => [fakeHimalayasJob()],
        'totalCount' => 1,
    ])]);

    $candidates = (new HimalayasDiscoveryProvider)->retrieve();

    expect($candidates)->toHaveCount(1);
    $candidate = $candidates[0];
    expect($candidate->source)->toBe(JobDiscoverySource::Himalayas)
        ->and($candidate->sourceJobId)->toBe('https://himalayas.app/companies/acme/jobs/senior-backend-engineer-123')
        ->and($candidate->company)->toBe('Acme Corp')
        ->and($candidate->title)->toBe('Senior Backend Engineer')
        ->and($candidate->remoteStatus)->toBe(JobRemoteStatus::Remote)
        ->and($candidate->compensationMin)->toBe(150000)
        ->and($candidate->compensationMax)->toBe(190000)
        ->and($candidate->applicationUrl)->toBe('https://himalayas.app/companies/acme/jobs/senior-backend-engineer-123')
        ->and($candidate->postedAt)->not->toBeNull()
        ->and($candidate->sourceMetadata['companySlug'])->toBe('acme');
});

it('handles a job missing optional fields without rejecting it', function () {
    Http::fake(['himalayas.test/*' => Http::response([
        'jobs' => [fakeHimalayasJob([
            'minSalary' => null,
            'maxSalary' => null,
            'description' => null,
            'locationRestrictions' => [],
        ])],
    ])]);

    $candidates = (new HimalayasDiscoveryProvider)->retrieve();

    expect($candidates)->toHaveCount(1);
    expect($candidates[0]->compensationMin)->toBeNull()
        ->and($candidates[0]->description)->toBeNull()
        ->and($candidates[0]->location)->toBeNull();
});

it('skips a job missing a required field (title/company/id) rather than throwing', function () {
    Http::fake(['himalayas.test/*' => Http::response([
        'jobs' => [
            fakeHimalayasJob(),
            fakeHimalayasJob(['title' => null, 'guid' => 'https://himalayas.app/2', 'applicationLink' => 'https://himalayas.app/2']),
        ],
    ])]);

    $candidates = (new HimalayasDiscoveryProvider)->retrieve();

    expect($candidates)->toHaveCount(1);
});

it('throws on a malformed response shape', function () {
    Http::fake(['himalayas.test/*' => Http::response(['unexpected' => true])]);

    expect(fn () => (new HimalayasDiscoveryProvider)->retrieve())
        ->toThrow(DiscoveryProviderException::class);
});

it('throws on a timeout/connection failure', function () {
    Http::fake(['himalayas.test/*' => fn () => throw new ConnectionException('timed out')]);

    expect(fn () => (new HimalayasDiscoveryProvider)->retrieve())
        ->toThrow(DiscoveryProviderException::class);
});

it('throws on a persistent 500 after retries', function () {
    Http::fake(['himalayas.test/*' => Http::response(null, 500)]);

    expect(fn () => (new HimalayasDiscoveryProvider)->retrieve())
        ->toThrow(DiscoveryProviderException::class);
});
