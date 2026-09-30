<?php

use App\Enums\DescriptionCompleteness;
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
        'additionalPlain' => 'Why Velaura?',
        'lists' => [
            ['text' => '', 'content' => '<h2>Responsibilities</h2><p>● Lead the compiler team.</p>'],
            ['text' => 'Qualifications', 'content' => '<p>● Strong C++.</p><p>● 10 years experience.</p>'],
        ],
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
        ->and($posting->sourceUpdatedAt)->not->toBeNull()
        ->and($posting->description)->toContain('Build compilers.')
        ->and($posting->description)->toContain('Why Velaura?')
        ->and($posting->description)->toContain('Responsibilities')
        ->and($posting->description)->toContain('Lead the compiler team.')
        ->and($posting->description)->toContain('Qualifications')
        ->and($posting->description)->toContain('Strong C++.')
        ->and($posting->descriptionCompleteness)->toBe(DescriptionCompleteness::Complete);
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

// Description assembly — descriptionPlain alone silently discarded the
// majority of a real Lever posting's content (see this adapter's own
// docblock); these prove descriptionPlain + additionalPlain + lists[]
// are all represented, in order, without duplication or stray markup.

it('represents descriptionPlain, additionalPlain, and every lists[] section in order', function () {
    Http::fake(['api.lever.co/*' => Http::response([fakeLeverJob()])]);

    $description = (new LeverCanonicalAdapter)->retrieveBoard('velaura')[0]->description;

    expect($description)
        ->toBeString()
        ->and(mb_strpos($description, 'Build compilers.'))->toBeLessThan(mb_strpos($description, 'Why Velaura?'))
        ->and(mb_strpos($description, 'Why Velaura?'))->toBeLessThan(mb_strpos($description, 'Responsibilities'))
        ->and(mb_strpos($description, 'Responsibilities'))->toBeLessThan(mb_strpos($description, 'Qualifications'));
});

it('preserves a lists[] section label supplied via the text field', function () {
    Http::fake(['api.lever.co/*' => Http::response([fakeLeverJob([
        'lists' => [['text' => 'Nice to Have', 'content' => '<p>Rust experience.</p>']],
    ])])]);

    expect((new LeverCanonicalAdapter)->retrieveBoard('velaura')[0]->description)
        ->toContain('Nice to Have')
        ->toContain('Rust experience.');
});

it('does not duplicate a section heading already embedded in lists[] content', function () {
    Http::fake(['api.lever.co/*' => Http::response([fakeLeverJob([
        'lists' => [['text' => 'Responsibilities', 'content' => '<h2>Responsibilities</h2><p>Own the roadmap.</p>']],
    ])])]);

    $description = (new LeverCanonicalAdapter)->retrieveBoard('velaura')[0]->description;

    expect(substr_count(mb_strtolower($description), 'responsibilities'))->toBe(1);
});

it('skips a lists[] entry with empty content without producing blank sections', function () {
    Http::fake(['api.lever.co/*' => Http::response([fakeLeverJob([
        'lists' => [
            ['text' => '', 'content' => ''],
            ['text' => 'Qualifications', 'content' => '<p>Strong C++.</p>'],
        ],
    ])])]);

    $description = (new LeverCanonicalAdapter)->retrieveBoard('velaura')[0]->description;

    expect($description)->not->toContain("\n\n\n")
        ->and(trim($description))->toBe($description);
});

it('assembles a description when additionalPlain is missing', function () {
    Http::fake(['api.lever.co/*' => Http::response([fakeLeverJob(['additionalPlain' => null])])]);

    $description = (new LeverCanonicalAdapter)->retrieveBoard('velaura')[0]->description;

    expect($description)->toContain('Build compilers.')->not->toContain('Why Velaura?');
});

it('assembles a description when lists is missing or empty', function () {
    Http::fake(['api.lever.co/*' => Http::response([fakeLeverJob(['lists' => null])])]);
    expect((new LeverCanonicalAdapter)->retrieveBoard('velaura')[0]->description)->toBe("Build compilers.\n\nWhy Velaura?");

    Http::fake(['api.lever.co/*' => Http::response([fakeLeverJob(['lists' => []])])]);
    expect((new LeverCanonicalAdapter)->retrieveBoard('velaura')[0]->description)->toBe("Build compilers.\n\nWhy Velaura?");
});

it('still produces a description for a descriptionPlain-only posting (no additional, no lists)', function () {
    Http::fake(['api.lever.co/*' => Http::response([fakeLeverJob(['additionalPlain' => null, 'lists' => []])])]);

    expect((new LeverCanonicalAdapter)->retrieveBoard('velaura')[0]->description)->toBe('Build compilers.');
});

it('converts lists[] HTML to readable plain text without stray markup or entities', function () {
    Http::fake(['api.lever.co/*' => Http::response([fakeLeverJob([
        'lists' => [['text' => '', 'content' => '<div><h2>Qualifications</h2><p>10+ years&nbsp;experience.</p><p>Strong C++.</p></div>']],
    ])])]);

    $description = (new LeverCanonicalAdapter)->retrieveBoard('velaura')[0]->description;

    expect($description)->not->toContain('<')
        ->not->toContain('&nbsp;')
        ->toContain('10+ years experience.')
        ->toContain('Strong C++.');
});

it('returns a null description when Lever supplies no content at all', function () {
    Http::fake(['api.lever.co/*' => Http::response([fakeLeverJob([
        'descriptionPlain' => null, 'additionalPlain' => null, 'lists' => [],
    ])])]);

    expect((new LeverCanonicalAdapter)->retrieveBoard('velaura')[0]->description)->toBeNull();
});
