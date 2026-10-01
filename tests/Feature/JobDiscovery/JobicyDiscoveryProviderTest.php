<?php

use App\Enums\DescriptionCompleteness;
use App\Enums\JobDiscoverySource;
use App\Enums\JobRemoteStatus;
use App\Support\JobDiscovery\Providers\DiscoveryProviderException;
use App\Support\JobDiscovery\Providers\JobicyDiscoveryProvider;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

beforeEach(function () {
    Sleep::fake();
    config(['services.jobicy.base_url' => 'https://jobicy.test/api/v2/remote-jobs']);
});

function fakeJobicyJob(array $overrides = []): array
{
    return array_merge([
        'id' => 154259,
        'url' => 'https://jobicy.com/jobs/154259-senior-backend-engineer',
        'jobSlug' => '154259-senior-backend-engineer',
        'jobTitle' => 'Senior Backend Engineer',
        'companyName' => 'Acme Corp',
        'jobIndustry' => ['Engineering'],
        'jobType' => ['Full-Time'],
        'jobGeo' => 'USA',
        'jobLevel' => 'Senior',
        'jobExcerpt' => 'Build backend systems&hellip;',
        'jobDescription' => '<p>Build backend systems.</p><h2>Responsibilities</h2><ul><li>Own services.</li><li>Ship features.</li></ul>',
        'pubDate' => '2026-09-01T12:00:00+00:00',
        'salaryMin' => 150000,
        'salaryMax' => 190000,
        'salaryCurrency' => 'USD',
        'salaryPeriod' => 'year',
    ], $overrides);
}

it('retrieves and normalizes a successful response', function () {
    Http::fake(['jobicy.test/*' => Http::response(['jobs' => [fakeJobicyJob()], 'jobCount' => 1])]);

    $candidates = (new JobicyDiscoveryProvider)->retrieve();

    expect($candidates)->toHaveCount(1);
    $candidate = $candidates[0];
    expect($candidate->source)->toBe(JobDiscoverySource::Jobicy)
        ->and($candidate->sourceJobId)->toBe('154259')
        ->and($candidate->company)->toBe('Acme Corp')
        ->and($candidate->title)->toBe('Senior Backend Engineer')
        ->and($candidate->remoteStatus)->toBe(JobRemoteStatus::Remote)
        ->and($candidate->location)->toBe('USA')
        ->and($candidate->employmentType)->toBe('Full-Time')
        ->and($candidate->compensationMin)->toBe(150000)
        ->and($candidate->compensationMax)->toBe(190000)
        ->and($candidate->compensationCurrency)->toBe('USD')
        ->and($candidate->compensationInterval)->toBe('year')
        ->and($candidate->applicationUrl)->toBe('https://jobicy.com/jobs/154259-senior-backend-engineer')
        ->and($candidate->postedAt)->not->toBeNull()
        ->and($candidate->sourceMetadata['jobLevel'])->toBe('Senior');
});

it('always declares Complete description completeness, using jobDescription rather than the short jobExcerpt', function () {
    Http::fake(['jobicy.test/*' => Http::response(['jobs' => [fakeJobicyJob()]])]);

    $candidate = (new JobicyDiscoveryProvider)->retrieve()[0];

    expect($candidate->descriptionCompleteness)->toBe(DescriptionCompleteness::Complete)
        ->and($candidate->description)->toContain('Build backend systems.')
        ->and($candidate->description)->not->toContain('hellip');
});

it('converts jobDescription HTML to readable plain text, preserving headings and list items', function () {
    Http::fake(['jobicy.test/*' => Http::response(['jobs' => [fakeJobicyJob()]])]);

    $description = (new JobicyDiscoveryProvider)->retrieve()[0]->description;

    expect($description)->not->toContain('<')
        ->toContain('Responsibilities')
        ->toContain('- Own services.')
        ->toContain('- Ship features.');
});

it('decodes HTML entities in companyName and jobTitle', function () {
    Http::fake(['jobicy.test/*' => Http::response(['jobs' => [fakeJobicyJob([
        'companyName' => 'hims &#038; hers',
        'jobTitle' => 'Staff Engineer &amp; Architect',
    ])]])]);

    $candidate = (new JobicyDiscoveryProvider)->retrieve()[0];

    expect($candidate->company)->toBe('hims & hers')
        ->and($candidate->title)->toBe('Staff Engineer & Architect');
});

it('leaves ordinary company/title strings with no entities unchanged', function () {
    Http::fake(['jobicy.test/*' => Http::response(['jobs' => [fakeJobicyJob([
        'companyName' => 'Acme Corp',
        'jobTitle' => 'Senior Backend Engineer',
    ])]])]);

    $candidate = (new JobicyDiscoveryProvider)->retrieve()[0];

    expect($candidate->company)->toBe('Acme Corp')
        ->and($candidate->title)->toBe('Senior Backend Engineer');
});

it('does not double-decode or corrupt a literal ampersand already present as text', function () {
    Http::fake(['jobicy.test/*' => Http::response(['jobs' => [fakeJobicyJob([
        'companyName' => 'R&D Labs',
    ])]])]);

    $candidate = (new JobicyDiscoveryProvider)->retrieve()[0];

    expect($candidate->company)->toBe('R&D Labs');
});

it('requests the native geo=usa provider-side filter', function () {
    Http::fake(['jobicy.test/*' => Http::response(['jobs' => []])]);

    (new JobicyDiscoveryProvider)->retrieve();

    Http::assertSent(fn ($request) => $request['geo'] === 'usa');
});

it('handles a job missing optional fields without rejecting it', function () {
    Http::fake(['jobicy.test/*' => Http::response(['jobs' => [fakeJobicyJob([
        'salaryMin' => null,
        'salaryMax' => null,
        'jobDescription' => null,
        'jobType' => null,
        'jobGeo' => null,
    ])]])]);

    $candidates = (new JobicyDiscoveryProvider)->retrieve();

    expect($candidates)->toHaveCount(1);
    expect($candidates[0]->compensationMin)->toBeNull()
        ->and($candidates[0]->description)->toBeNull()
        ->and($candidates[0]->employmentType)->toBeNull()
        ->and($candidates[0]->location)->toBeNull();
});

it('skips a job missing a required field (id/title/company) rather than throwing', function () {
    Http::fake(['jobicy.test/*' => Http::response(['jobs' => [
        fakeJobicyJob(),
        fakeJobicyJob(['id' => null]),
    ]])]);

    expect((new JobicyDiscoveryProvider)->retrieve())->toHaveCount(1);
});

it('throws on a malformed response shape', function () {
    Http::fake(['jobicy.test/*' => Http::response(['unexpected' => true])]);

    expect(fn () => (new JobicyDiscoveryProvider)->retrieve())
        ->toThrow(DiscoveryProviderException::class);
});

it('throws on a timeout/connection failure', function () {
    Http::fake(['jobicy.test/*' => fn () => throw new ConnectionException('timed out')]);

    expect(fn () => (new JobicyDiscoveryProvider)->retrieve())
        ->toThrow(DiscoveryProviderException::class);
});

it('throws on a persistent 500 after retries', function () {
    Http::fake(['jobicy.test/*' => Http::response(null, 500)]);

    expect(fn () => (new JobicyDiscoveryProvider)->retrieve())
        ->toThrow(DiscoveryProviderException::class);
});

it('decodes numeric and named ampersands in both display fields', function (string $encoded, string $decoded) {
    Http::fake(['jobicy.test/*' => Http::response(['jobs' => [fakeJobicyJob([
        'companyName' => $encoded, 'jobTitle' => $encoded,
    ])]])]);
    $candidate = (new JobicyDiscoveryProvider)->retrieve()[0];
    expect($candidate->company)->toBe($decoded)->and($candidate->title)->toBe($decoded);
})->with([
    ['hims &#038; hers', 'hims & hers'],
    ['Foo &amp; Bar', 'Foo & Bar'],
]);
