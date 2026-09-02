<?php

use App\Exceptions\NoCareerProfileException;
use App\Models\CareerProfile;
use App\Support\CurrentCareerProfile;

it('resolves the only career profile', function () {
    $profile = CareerProfile::factory()->create();

    expect(CurrentCareerProfile::resolve()->is($profile))->toBeTrue()
        ->and(CurrentCareerProfile::tryResolve()->is($profile))->toBeTrue();
});

it('deterministically resolves the oldest profile when more than one exists', function () {
    $first = CareerProfile::factory()->create();
    $second = CareerProfile::factory()->create();

    expect(CurrentCareerProfile::resolve()->is($first))->toBeTrue()
        ->and(CurrentCareerProfile::resolve()->isNot($second))->toBeTrue();
});

it('throws clearly from resolve() when no career profile exists', function () {
    expect(fn () => CurrentCareerProfile::resolve())->toThrow(NoCareerProfileException::class);
});

it('returns null from tryResolve() when no career profile exists, rather than throwing', function () {
    expect(CurrentCareerProfile::tryResolve())->toBeNull();
});
