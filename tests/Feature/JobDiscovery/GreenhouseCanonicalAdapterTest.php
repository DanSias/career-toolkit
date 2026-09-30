<?php

use App\Enums\JobCanonicalSource;
use App\Support\JobDiscovery\Canonical\GreenhouseCanonicalAdapter;
use App\Support\JobDiscovery\Providers\DiscoveryProviderException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

beforeEach(function () {
    Sleep::fake();
});

function fakeGreenhouseJob(array $overrides = []): array
{
    return array_merge([
        'id' => 4461450008,
        'title' => 'Account Executive, AI Native',
        'content' => '<p>Job description.</p>',
        'location' => ['name' => 'New York City, NY'],
        'updated_at' => '2026-08-21T21:32:54-04:00',
        'requisition_id' => '3356',
        'absolute_url' => 'https://job-boards.greenhouse.io/anthropic/jobs/4461450008',
        'departments' => [['name' => 'Sales']],
        'offices' => [['name' => 'New York']],
    ], $overrides);
}

it('retrieves and normalizes a board listing', function () {
    Http::fake(['boards-api.greenhouse.io/*' => Http::response(['jobs' => [fakeGreenhouseJob()]])]);

    $board = (new GreenhouseCanonicalAdapter)->retrieveBoard('anthropic');

    expect($board)->toHaveCount(1);
    $posting = $board[0];
    expect($posting->canonicalSource)->toBe(JobCanonicalSource::Greenhouse)
        ->and($posting->canonicalSourceId)->toBe('4461450008')
        ->and($posting->title)->toBe('Account Executive, AI Native')
        ->and($posting->applicationUrl)->toBe('https://job-boards.greenhouse.io/anthropic/jobs/4461450008')
        ->and($posting->sourceUpdatedAt)->not->toBeNull()
        ->and($posting->sourceMetadata['requisition_id'])->toBe('3356');
});

it('requests content=true so descriptions are included in one call', function () {
    Http::fake(['boards-api.greenhouse.io/*' => Http::response(['jobs' => []])]);

    (new GreenhouseCanonicalAdapter)->retrieveBoard('anthropic');

    Http::assertSent(fn ($request) => $request['content'] === 'true');
});

it('never assumes compensation is present', function () {
    Http::fake(['boards-api.greenhouse.io/*' => Http::response(['jobs' => [fakeGreenhouseJob()]])]);

    $posting = (new GreenhouseCanonicalAdapter)->retrieveBoard('anthropic')[0];

    expect($posting->compensationMin)->toBeNull()
        ->and($posting->compensationMax)->toBeNull();
});

it('skips a job missing required fields rather than throwing', function () {
    Http::fake(['boards-api.greenhouse.io/*' => Http::response(['jobs' => [
        fakeGreenhouseJob(),
        fakeGreenhouseJob(['id' => null]),
    ]])]);

    expect((new GreenhouseCanonicalAdapter)->retrieveBoard('anthropic'))->toHaveCount(1);
});

it('throws on a malformed response shape', function () {
    Http::fake(['boards-api.greenhouse.io/*' => Http::response(['unexpected' => true])]);

    expect(fn () => (new GreenhouseCanonicalAdapter)->retrieveBoard('anthropic'))
        ->toThrow(DiscoveryProviderException::class);
});

it('throws when the board does not exist (404)', function () {
    Http::fake(['boards-api.greenhouse.io/*' => Http::response(null, 404)]);

    expect(fn () => (new GreenhouseCanonicalAdapter)->retrieveBoard('nonexistent'))
        ->toThrow(DiscoveryProviderException::class);
});
