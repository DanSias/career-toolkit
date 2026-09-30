<?php

use App\Enums\JobCanonicalSource;
use App\Enums\JobRemoteStatus;
use App\Support\JobDiscovery\Canonical\LeverCanonicalAdapter;
use App\Support\JobDiscovery\Providers\DiscoveryProviderException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

beforeEach(function () {
    Sleep::fake();
});

function fakeLeverJob(array $overrides = []): array
{
    return array_merge([
        'id' => '9cb4c077-a07b-4157-b6ca-eddbf5daef08',
        'text' => 'Accelerator Compiler and Tool Chain Lead',
        'descriptionPlain' => 'Build compilers.',
        'categories' => [
            'commitment' => 'Full-time',
            'department' => 'Software',
            'location' => 'Santa Clara, CA',
            'team' => 'Compiler & Toolchain',
        ],
        'country' => 'US',
        'workplaceType' => 'onsite',
        'createdAt' => 1784172458969,
        'hostedUrl' => 'https://jobs.lever.co/velaura/9cb4c077-a07b-4157-b6ca-eddbf5daef08',
        'salaryRange' => ['interval' => 'per-year-salary', 'currency' => 'USD', 'min' => 200000, 'max' => 300000],
    ], $overrides);
}

it('retrieves and normalizes a board listing (a plain JSON array, not an object)', function () {
    Http::fake(['api.lever.co/*' => Http::response([fakeLeverJob()])]);

    $board = (new LeverCanonicalAdapter)->retrieveBoard('velaura');

    expect($board)->toHaveCount(1);
    $posting = $board[0];
    expect($posting->canonicalSource)->toBe(JobCanonicalSource::Lever)
        ->and($posting->canonicalSourceId)->toBe('9cb4c077-a07b-4157-b6ca-eddbf5daef08')
        ->and($posting->title)->toBe('Accelerator Compiler and Tool Chain Lead')
        ->and($posting->remoteStatus)->toBe(JobRemoteStatus::Onsite)
        ->and($posting->compensationMin)->toBe(200000)
        ->and($posting->compensationMax)->toBe(300000)
        ->and($posting->compensationCurrency)->toBe('USD')
        ->and($posting->applicationUrl)->toBe('https://jobs.lever.co/velaura/9cb4c077-a07b-4157-b6ca-eddbf5daef08')
        ->and($posting->sourceUpdatedAt)->not->toBeNull();
});

it('normalizes remote workplaceType correctly', function () {
    Http::fake(['api.lever.co/*' => Http::response([fakeLeverJob(['workplaceType' => 'remote'])])]);

    expect((new LeverCanonicalAdapter)->retrieveBoard('velaura')[0]->remoteStatus)->toBe(JobRemoteStatus::Remote);
});

it('handles a job missing salary/optional fields without rejecting it', function () {
    Http::fake(['api.lever.co/*' => Http::response([fakeLeverJob(['salaryRange' => null, 'workplaceType' => null])])]);

    $posting = (new LeverCanonicalAdapter)->retrieveBoard('velaura')[0];

    expect($posting->compensationMin)->toBeNull()
        ->and($posting->remoteStatus)->toBeNull();
});

it('skips a job missing required fields rather than throwing', function () {
    Http::fake(['api.lever.co/*' => Http::response([
        fakeLeverJob(),
        fakeLeverJob(['id' => null]),
    ])]);

    expect((new LeverCanonicalAdapter)->retrieveBoard('velaura'))->toHaveCount(1);
});

it('throws on a malformed (non-array) response shape', function () {
    Http::fake(['api.lever.co/*' => Http::response(['unexpected' => true])]);

    expect(fn () => (new LeverCanonicalAdapter)->retrieveBoard('velaura'))
        ->toThrow(DiscoveryProviderException::class);
});

it('throws when the board does not exist (404)', function () {
    Http::fake(['api.lever.co/*' => Http::response(null, 404)]);

    expect(fn () => (new LeverCanonicalAdapter)->retrieveBoard('nonexistent'))
        ->toThrow(DiscoveryProviderException::class);
});
