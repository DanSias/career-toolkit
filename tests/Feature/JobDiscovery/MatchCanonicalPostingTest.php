<?php

use App\Enums\JobCanonicalSource;
use App\Enums\JobDiscoverySource;
use App\Support\JobDiscovery\Canonical\CanonicalJobPosting;
use App\Support\JobDiscovery\Canonical\MatchCanonicalPosting;
use App\Support\JobDiscovery\DiscoveredJobCandidate;
use Carbon\CarbonImmutable;

function discoveredCandidateFor(string $title, ?string $location = 'United States'): DiscoveredJobCandidate
{
    return new DiscoveredJobCandidate(
        source: JobDiscoverySource::Himalayas,
        sourceJobId: 'himalayas-1',
        company: 'Acme',
        title: $title,
        description: null,
        location: $location,
        remoteStatus: null,
        employmentType: null,
        compensationMin: null,
        compensationMax: null,
        compensationCurrency: null,
        compensationInterval: null,
        applicationUrl: null,
        postedAt: null,
        sourceUpdatedAt: null,
        discoveredAt: CarbonImmutable::now(),
    );
}

function canonicalPosting(string $id, string $title, ?string $location = null): CanonicalJobPosting
{
    return new CanonicalJobPosting(
        canonicalSource: JobCanonicalSource::Greenhouse,
        canonicalSourceId: $id,
        title: $title,
        description: null,
        location: $location,
        remoteStatus: null,
        employmentType: null,
        compensationMin: null,
        compensationMax: null,
        compensationCurrency: null,
        compensationInterval: null,
        applicationUrl: null,
        sourceUpdatedAt: null,
    );
}

it('matches on an exact normalized title when there is exactly one candidate', function () {
    $candidate = discoveredCandidateFor('Senior Software Engineer');
    $board = [canonicalPosting('1', 'Senior Software Engineer'), canonicalPosting('2', 'Product Manager')];

    $match = (new MatchCanonicalPosting)->match($candidate, $board);

    expect($match?->canonicalSourceId)->toBe('1');
});

it('matches case/whitespace-insensitively', function () {
    $candidate = discoveredCandidateFor('  senior   software engineer  ');
    $board = [canonicalPosting('1', 'Senior Software Engineer')];

    expect((new MatchCanonicalPosting)->match($candidate, $board)?->canonicalSourceId)->toBe('1');
});

it('returns null when no title matches', function () {
    $candidate = discoveredCandidateFor('Senior Software Engineer');
    $board = [canonicalPosting('1', 'Product Manager')];

    expect((new MatchCanonicalPosting)->match($candidate, $board))->toBeNull();
});

it('uses location to break a tie between two identically-titled postings', function () {
    $candidate = discoveredCandidateFor('Software Engineer', 'New York City');
    $board = [
        canonicalPosting('1', 'Software Engineer', 'New York City, NY'),
        canonicalPosting('2', 'Software Engineer', 'San Francisco, CA'),
    ];

    expect((new MatchCanonicalPosting)->match($candidate, $board)?->canonicalSourceId)->toBe('1');
});

it('returns null (never guesses) when location cannot break the tie', function () {
    $candidate = discoveredCandidateFor('Software Engineer', 'Remote');
    $board = [
        canonicalPosting('1', 'Software Engineer', 'New York City, NY'),
        canonicalPosting('2', 'Software Engineer', 'San Francisco, CA'),
    ];

    expect((new MatchCanonicalPosting)->match($candidate, $board))->toBeNull();
});

it('returns null when the candidate has no location and titles are ambiguous', function () {
    $candidate = discoveredCandidateFor('Software Engineer', null);
    $board = [
        canonicalPosting('1', 'Software Engineer', 'New York City, NY'),
        canonicalPosting('2', 'Software Engineer', 'San Francisco, CA'),
    ];

    expect((new MatchCanonicalPosting)->match($candidate, $board))->toBeNull();
});

it('returns null against an empty board', function () {
    $candidate = discoveredCandidateFor('Software Engineer');

    expect((new MatchCanonicalPosting)->match($candidate, []))->toBeNull();
});
