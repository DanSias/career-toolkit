<?php

use App\Enums\EvidenceSource;
use App\Enums\Verification;
use App\Models\CareerFact;
use App\Models\Evidence;

it('lets a single-source fact be fully verified', function () {
    $fact = CareerFact::factory()->create(['verification' => Verification::Verified]);
    Evidence::factory()->for($fact)->create(['source' => EvidenceSource::UserConfirmed]);

    expect($fact->verification)->toBe(Verification::Verified)
        ->and($fact->evidence)->toHaveCount(1);
});

it('lets a multi-source, well-evidenced fact still need confirmation', function () {
    $fact = CareerFact::factory()->create(['verification' => Verification::NeedsConfirmation]);
    Evidence::factory()->resume()->for($fact)->create();
    Evidence::factory()->portfolio()->for($fact)->create();

    expect($fact->verification)->toBe(Verification::NeedsConfirmation)
        ->and($fact->evidence)->toHaveCount(2);
});

it('does not derive verification from evidence source or count', function () {
    // Two facts with identical evidence coverage but different trust
    // levels — proves verification is a genuinely independent dimension,
    // not something computed from how many/which sources back a fact.
    $stronglySupported = CareerFact::factory()->create(['verification' => Verification::StronglySupported]);
    $needsConfirmation = CareerFact::factory()->create(['verification' => Verification::NeedsConfirmation]);

    Evidence::factory()->portfolio()->for($stronglySupported)->create();
    Evidence::factory()->portfolio()->for($needsConfirmation)->create();

    expect($stronglySupported->evidence->pluck('source')->all())
        ->toEqual($needsConfirmation->evidence->pluck('source')->all())
        ->and($stronglySupported->verification)->not->toBe($needsConfirmation->verification);
});
