<?php

use App\Enums\JobCanonicalSource;
use App\Models\KnownAtsBoard;
use App\Support\JobDiscovery\Canonical\ResolveAtsBoard;

it('resolves a known company to its ATS board', function () {
    KnownAtsBoard::factory()->create([
        'company_name' => 'acme',
        'ats_type' => JobCanonicalSource::Greenhouse,
        'board_identifier' => 'acme-inc',
    ]);

    $board = (new ResolveAtsBoard)->resolve('Acme');

    expect($board)->not->toBeNull()
        ->and($board->ats_type)->toBe(JobCanonicalSource::Greenhouse)
        ->and($board->board_identifier)->toBe('acme-inc');
});

it('resolves regardless of casing and surrounding whitespace', function () {
    KnownAtsBoard::factory()->create(['company_name' => 'acme']);

    expect((new ResolveAtsBoard)->resolve('  ACME  '))->not->toBeNull();
});

it('returns null for an unknown company rather than throwing', function () {
    expect((new ResolveAtsBoard)->resolve('Totally Unknown Co'))->toBeNull();
});
