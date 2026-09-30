<?php

use App\Support\JobDiscovery\Canonical\AshbyCanonicalAdapter;
use App\Support\JobDiscovery\Canonical\GreenhouseCanonicalAdapter;
use App\Support\JobDiscovery\Canonical\LeverCanonicalAdapter;
use App\Support\JobDiscovery\Providers\AdzunaDiscoveryProvider;
use App\Support\JobDiscovery\Providers\DiscoveryProviderException;
use App\Support\JobDiscovery\Providers\HimalayasDiscoveryProvider;
use Illuminate\Support\Facades\Http;

it('converts malformed individual entries into provider failures', function (string $provider, array $body) {
    config(['services.adzuna.app_id' => 'test', 'services.adzuna.app_key' => 'test', 'services.adzuna.country' => 'us']);
    Http::fake(['*' => Http::response($body)]);
    expect(fn () => match ($provider) {
        'himalayas' => (new HimalayasDiscoveryProvider)->retrieve(),
        'adzuna' => (new AdzunaDiscoveryProvider)->retrieve(),
        'greenhouse' => (new GreenhouseCanonicalAdapter)->retrieveBoard('test'),
        'lever' => (new LeverCanonicalAdapter)->retrieveBoard('test'),
        'ashby' => (new AshbyCanonicalAdapter)->retrieveBoard('test'),
    })->toThrow(DiscoveryProviderException::class, 'malformed job entry');
})->with([
    ['himalayas', ['jobs' => [null]]],
    ['adzuna', ['results' => ['invalid']]],
    ['greenhouse', ['jobs' => [123]]],
    ['lever', [false]],
    ['ashby', ['jobs' => ['invalid']]],
]);

it('converts malformed nested fields into the same provider failure path', function () {
    Http::fake(['*' => Http::response(['jobs' => [['id' => 1, 'title' => 'Engineer', 'departments' => 'invalid']]])]);
    expect(fn () => (new GreenhouseCanonicalAdapter)->retrieveBoard('test'))
        ->toThrow(DiscoveryProviderException::class, 'malformed job fields');
});
