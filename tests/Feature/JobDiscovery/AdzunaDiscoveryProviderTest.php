<?php

use App\Enums\DescriptionCompleteness;
use App\Enums\JobDiscoverySource;
use App\Support\JobDiscovery\Providers\AdzunaDiscoveryProvider;
use App\Support\JobDiscovery\Providers\DiscoveryProviderException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

beforeEach(function () {
    Sleep::fake();
    config([
        'services.adzuna.app_id' => 'test-id',
        'services.adzuna.app_key' => 'test-key',
        'services.adzuna.country' => 'us',
    ]);
});

function fakeAdzunaJob(array $overrides = []): array
{
    return array_merge([
        'id' => '129698749',
        'title' => 'Senior Software Engineer',
        'description' => 'Build things.',
        'company' => ['display_name' => 'Acme Corp'],
        'location' => ['display_name' => 'Remote'],
        'salary_min' => 150000,
        'salary_max' => 190000,
        'contract_time' => 'full_time',
        'contract_type' => 'permanent',
        'category' => ['label' => 'IT Jobs'],
        'created' => '2026-09-01T12:00:00Z',
        'redirect_url' => 'https://www.adzuna.com/land/ad/129698749',
    ], $overrides);
}

it('throws immediately without making a request when credentials are missing', function () {
    config(['services.adzuna.app_id' => '', 'services.adzuna.app_key' => '']);
    Http::fake();

    expect(fn () => (new AdzunaDiscoveryProvider)->retrieve())
        ->toThrow(DiscoveryProviderException::class);

    Http::assertNothingSent();
});

it('retrieves and normalizes a successful response', function () {
    Http::fake(['api.adzuna.com/*' => Http::response(['results' => [fakeAdzunaJob()]])]);

    $candidates = (new AdzunaDiscoveryProvider)->retrieve();

    expect($candidates)->toHaveCount(1);
    $candidate = $candidates[0];
    expect($candidate->source)->toBe(JobDiscoverySource::Adzuna)
        ->and($candidate->sourceJobId)->toBe('129698749')
        ->and($candidate->company)->toBe('Acme Corp')
        ->and($candidate->title)->toBe('Senior Software Engineer')
        ->and($candidate->compensationMin)->toBe(150000)
        ->and($candidate->compensationCurrency)->toBe('USD')
        ->and($candidate->applicationUrl)->toBe('https://www.adzuna.com/land/ad/129698749')
        ->and($candidate->postedAt)->not->toBeNull();
});

it('always declares Preview description completeness, never Complete', function () {
    Http::fake(['api.adzuna.com/*' => Http::response(['results' => [fakeAdzunaJob()]])]);

    $candidate = (new AdzunaDiscoveryProvider)->retrieve()[0];

    expect($candidate->descriptionCompleteness)->toBe(DescriptionCompleteness::Preview);
});

it('sends the configured app_id/app_key as query parameters', function () {
    Http::fake(['api.adzuna.com/*' => Http::response(['results' => []])]);

    (new AdzunaDiscoveryProvider)->retrieve();

    Http::assertSent(fn ($request) => $request['app_id'] === 'test-id' && $request['app_key'] === 'test-key');
});

it('handles a job missing optional fields without rejecting it', function () {
    Http::fake(['api.adzuna.com/*' => Http::response(['results' => [fakeAdzunaJob([
        'salary_min' => null,
        'salary_max' => null,
        'description' => null,
        'location' => null,
    ])]])]);

    $candidates = (new AdzunaDiscoveryProvider)->retrieve();

    expect($candidates)->toHaveCount(1);
    expect($candidates[0]->compensationMin)->toBeNull()
        ->and($candidates[0]->compensationCurrency)->toBeNull()
        ->and($candidates[0]->location)->toBeNull();
});

it('throws on an auth/config failure response', function () {
    Http::fake(['api.adzuna.com/*' => Http::response(['exception' => 'AUTH_FAIL'], 401)]);

    expect(fn () => (new AdzunaDiscoveryProvider)->retrieve())
        ->toThrow(DiscoveryProviderException::class);
});

it('throws on a rate/quota-style failure after retries', function () {
    Http::fake(['api.adzuna.com/*' => Http::response(null, 429)]);

    expect(fn () => (new AdzunaDiscoveryProvider)->retrieve())
        ->toThrow(DiscoveryProviderException::class);
});

it('throws on a malformed response shape', function () {
    Http::fake(['api.adzuna.com/*' => Http::response(['unexpected' => true])]);

    expect(fn () => (new AdzunaDiscoveryProvider)->retrieve())
        ->toThrow(DiscoveryProviderException::class);
});
