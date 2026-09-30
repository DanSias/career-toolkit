<?php

use App\Enums\JobCanonicalSource;
use App\Enums\JobRemoteStatus;
use App\Support\JobDiscovery\Canonical\AshbyCanonicalAdapter;
use App\Support\JobDiscovery\Providers\DiscoveryProviderException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

beforeEach(function () {
    Sleep::fake();
});

function fakeAshbyJob(array $overrides = []): array
{
    return array_merge([
        'id' => '7458d4e9-da2e-47bd-98cb-adfda43d42b2',
        'title' => 'Engineering Manager - EU',
        'department' => 'Engineering',
        'team' => 'EMEA Engineering',
        'employmentType' => 'FullTime',
        'location' => 'Remote - European Union',
        'publishedAt' => '2024-03-04T14:29:08.532+00:00',
        'isRemote' => true,
        'workplaceType' => 'Remote',
        'jobUrl' => 'https://jobs.ashbyhq.com/ashby/7458d4e9-da2e-47bd-98cb-adfda43d42b2',
        'descriptionPlain' => 'Manage engineers.',
        'compensation' => ['compensationTierSummary' => '€110K – €185K'],
    ], $overrides);
}

it('retrieves and normalizes a board listing', function () {
    Http::fake(['api.ashbyhq.com/*' => Http::response(['jobs' => [fakeAshbyJob()]])]);

    $board = (new AshbyCanonicalAdapter)->retrieveBoard('ashby');

    expect($board)->toHaveCount(1);
    $posting = $board[0];
    expect($posting->canonicalSource)->toBe(JobCanonicalSource::Ashby)
        ->and($posting->canonicalSourceId)->toBe('7458d4e9-da2e-47bd-98cb-adfda43d42b2')
        ->and($posting->title)->toBe('Engineering Manager - EU')
        ->and($posting->remoteStatus)->toBe(JobRemoteStatus::Remote)
        ->and($posting->applicationUrl)->toBe('https://jobs.ashbyhq.com/ashby/7458d4e9-da2e-47bd-98cb-adfda43d42b2')
        ->and($posting->sourceUpdatedAt)->not->toBeNull()
        ->and($posting->sourceMetadata['compensationSummary'])->toBe('€110K – €185K');
});

it('never fabricates compensationMin/Max from the free-text compensation summary', function () {
    Http::fake(['api.ashbyhq.com/*' => Http::response(['jobs' => [fakeAshbyJob()]])]);

    $posting = (new AshbyCanonicalAdapter)->retrieveBoard('ashby')[0];

    expect($posting->compensationMin)->toBeNull()
        ->and($posting->compensationMax)->toBeNull();
});

it('falls back to workplaceType when isRemote is absent', function () {
    Http::fake(['api.ashbyhq.com/*' => Http::response(['jobs' => [fakeAshbyJob(['isRemote' => false, 'workplaceType' => 'Hybrid'])]])]);

    expect((new AshbyCanonicalAdapter)->retrieveBoard('ashby')[0]->remoteStatus)->toBe(JobRemoteStatus::Hybrid);
});

it('skips a job missing required fields rather than throwing', function () {
    Http::fake(['api.ashbyhq.com/*' => Http::response(['jobs' => [
        fakeAshbyJob(),
        fakeAshbyJob(['id' => null]),
    ]])]);

    expect((new AshbyCanonicalAdapter)->retrieveBoard('ashby'))->toHaveCount(1);
});

it('throws on a malformed response shape', function () {
    Http::fake(['api.ashbyhq.com/*' => Http::response(['unexpected' => true])]);

    expect(fn () => (new AshbyCanonicalAdapter)->retrieveBoard('ashby'))
        ->toThrow(DiscoveryProviderException::class);
});

it('throws when the board does not exist (404)', function () {
    Http::fake(['api.ashbyhq.com/*' => Http::response(null, 404)]);

    expect(fn () => (new AshbyCanonicalAdapter)->retrieveBoard('nonexistent'))
        ->toThrow(DiscoveryProviderException::class);
});
