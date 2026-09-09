<?php

use App\Models\Role;
use App\Support\CareerData\RoleDateFormatter;

it('formats a current role with month precision as an open-ended range', function () {
    $role = new Role(['start_year' => 2025, 'start_month' => 5, 'end_year' => null, 'end_month' => null]);

    $result = RoleDateFormatter::format($role);

    expect($result['label'])->toBe('May 2025 – Present')
        ->and($result['is_current'])->toBeTrue();
});

it('formats a closed role with month precision on both ends', function () {
    $role = new Role(['start_year' => 2015, 'start_month' => 9, 'end_year' => 2024, 'end_month' => 7]);

    $result = RoleDateFormatter::format($role);

    expect($result['label'])->toBe('September 2015 – July 2024')
        ->and($result['is_current'])->toBeFalse();
});

it('does not invent a month when only a year is evidenced', function () {
    $role = new Role(['start_year' => 2013, 'start_month' => null, 'end_year' => 2015, 'end_month' => null]);

    $result = RoleDateFormatter::format($role);

    expect($result['label'])->toBe('2013 – 2015');
});

it('mixes month and year-only precision correctly on the same role', function () {
    $role = new Role(['start_year' => 2013, 'start_month' => null, 'end_year' => 2015, 'end_month' => 9]);

    expect(RoleDateFormatter::format($role)['label'])->toBe('2013 – September 2015');
});

it('treats a null end_year as current regardless of end_month', function () {
    $role = new Role(['start_year' => 2020, 'start_month' => 1, 'end_year' => null, 'end_month' => null]);

    expect(RoleDateFormatter::format($role)['is_current'])->toBeTrue();
});

// --- Abbreviated (resume) formatting ---------------------------------------

it('formats a current role with three-letter month abbreviations as an open-ended range', function () {
    $result = RoleDateFormatter::formatRangeAbbreviated(2025, 5, null, null);

    expect($result['label'])->toBe('May 2025 – Present')
        ->and($result['is_current'])->toBeTrue();
});

it('formats a closed role with three-letter month abbreviations on both ends', function () {
    $result = RoleDateFormatter::formatRangeAbbreviated(2015, 9, 2024, 7);

    expect($result['label'])->toBe('Sep 2015 – Jul 2024')
        ->and($result['is_current'])->toBeFalse();
});

it('abbreviates September as "Sep", not "Sept"', function () {
    expect(RoleDateFormatter::formatRangeAbbreviated(2013, 9, 2015, 9)['label'])->toBe('Sep 2013 – Sep 2015');
});

it('abbreviates every month 1 through 12 to exactly three letters', function () {
    $expected = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

    foreach ($expected as $month => $abbreviation) {
        $label = RoleDateFormatter::formatRangeAbbreviated(2020, $month + 1, null, null)['label'];

        expect($label)->toBe("{$abbreviation} 2020 – Present");
    }
});

it('does not invent a month when only a year is evidenced, in abbreviated form', function () {
    expect(RoleDateFormatter::formatRangeAbbreviated(2013, null, 2015, null)['label'])->toBe('2013 – 2015');
});

it('does not affect the full-month-name formatRange() output used elsewhere', function () {
    expect(RoleDateFormatter::formatRange(2015, 9, 2024, 7)['label'])->toBe('September 2015 – July 2024');
});
