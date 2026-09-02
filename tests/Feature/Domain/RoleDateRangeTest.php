<?php

use App\Exceptions\InvalidRoleDateRangeException;
use App\Models\Role;

it('persists a role with year-only start precision and no end', function () {
    $role = Role::factory()->create([
        'start_year' => 2013,
        'start_month' => null,
        'end_year' => null,
        'end_month' => null,
    ]);

    expect($role->exists)->toBeTrue();
});

it('persists a role with full month/year precision on both ends', function () {
    $role = Role::factory()->create([
        'start_year' => 2015,
        'start_month' => 9,
        'end_year' => 2024,
        'end_month' => 7,
    ]);

    expect($role->exists)->toBeTrue();
});

it('rejects an out-of-range start month', function () {
    expect(fn () => Role::factory()->create(['start_month' => 13]))
        ->toThrow(InvalidRoleDateRangeException::class);

    expect(fn () => Role::factory()->create(['start_month' => 0]))
        ->toThrow(InvalidRoleDateRangeException::class);
});

it('rejects an out-of-range end month', function () {
    expect(fn () => Role::factory()->create(['end_year' => 2024, 'end_month' => 13]))
        ->toThrow(InvalidRoleDateRangeException::class);
});

it('rejects an end month with no end year', function () {
    expect(fn () => Role::factory()->create(['end_year' => null, 'end_month' => 6]))
        ->toThrow(InvalidRoleDateRangeException::class);
});

it('rejects an end year before the start year', function () {
    expect(fn () => Role::factory()->create(['start_year' => 2020, 'end_year' => 2019]))
        ->toThrow(InvalidRoleDateRangeException::class);
});

it('rejects an end month before the start month within the same year', function () {
    expect(fn () => Role::factory()->create([
        'start_year' => 2020, 'start_month' => 6,
        'end_year' => 2020, 'end_month' => 3,
    ]))->toThrow(InvalidRoleDateRangeException::class);
});

it('allows an end month equal to or after the start month within the same year', function () {
    $role = Role::factory()->create([
        'start_year' => 2020, 'start_month' => 6,
        'end_year' => 2020, 'end_month' => 6,
    ]);

    expect($role->exists)->toBeTrue();
});

it('rejects changing an existing valid role to an invalid date range on update', function () {
    $role = Role::factory()->create([
        'start_year' => 2015, 'start_month' => 9,
        'end_year' => null, 'end_month' => null,
    ]);

    expect(fn () => $role->update(['end_year' => 2010]))
        ->toThrow(InvalidRoleDateRangeException::class);

    expect($role->fresh()->end_year)->toBeNull();
});
