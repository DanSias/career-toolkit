<?php

namespace App\Support\CareerData;

use App\Models\Role;

/**
 * Turns a Role's month/year-precision date columns into a human-readable
 * label, without ever inventing a day or a month that wasn't evidenced.
 */
final class RoleDateFormatter
{
    private const MONTHS = [
        1 => 'January', 2 => 'February', 3 => 'March', 4 => 'April',
        5 => 'May', 6 => 'June', 7 => 'July', 8 => 'August',
        9 => 'September', 10 => 'October', 11 => 'November', 12 => 'December',
    ];

    /**
     * Three-letter abbreviations, "Sep" not "Sept" — used only by the
     * resume renderer (GenerateResumeDocument); every other consumer
     * (e.g. the Career Data admin UI) keeps the full month name via
     * format()/formatRange() above, unaffected by this.
     */
    private const ABBREVIATED_MONTHS = [
        1 => 'Jan', 2 => 'Feb', 3 => 'Mar', 4 => 'Apr',
        5 => 'May', 6 => 'Jun', 7 => 'Jul', 8 => 'Aug',
        9 => 'Sep', 10 => 'Oct', 11 => 'Nov', 12 => 'Dec',
    ];

    /**
     * @return array{label: string, is_current: bool}
     */
    public static function format(Role $role): array
    {
        return self::formatRange($role->start_year, $role->start_month, $role->end_year, $role->end_month);
    }

    /**
     * The primitive-accepting core — used directly wherever role dates
     * are already frozen onto another row (e.g.
     * App\Models\ResumeVariantExperienceRole) rather than read live
     * from a Role model, so the exact same formatting rule applies to
     * both without re-deriving it.
     *
     * @return array{label: string, is_current: bool}
     */
    public static function formatRange(?int $startYear, ?int $startMonth, ?int $endYear, ?int $endMonth): array
    {
        $isCurrent = $endYear === null;

        $start = self::point($startYear, $startMonth);
        $end = $isCurrent ? 'Present' : self::point($endYear, $endMonth);

        return [
            'label' => "{$start} – {$end}",
            'is_current' => $isCurrent,
        ];
    }

    private static function point(?int $year, ?int $month): string
    {
        if ($year === null) {
            return '';
        }

        return $month !== null ? self::MONTHS[$month].' '.$year : (string) $year;
    }

    /**
     * Same shape and rules as formatRange() — never invents a day or a
     * month that wasn't evidenced — but with three-letter month
     * abbreviations for the resume's more compact date treatment. A
     * deterministic formatting choice only; never model-authored text.
     *
     * @return array{label: string, is_current: bool}
     */
    public static function formatRangeAbbreviated(?int $startYear, ?int $startMonth, ?int $endYear, ?int $endMonth): array
    {
        $isCurrent = $endYear === null;

        $start = self::pointAbbreviated($startYear, $startMonth);
        $end = $isCurrent ? 'Present' : self::pointAbbreviated($endYear, $endMonth);

        return [
            'label' => "{$start} – {$end}",
            'is_current' => $isCurrent,
        ];
    }

    private static function pointAbbreviated(?int $year, ?int $month): string
    {
        if ($year === null) {
            return '';
        }

        return $month !== null ? self::ABBREVIATED_MONTHS[$month].' '.$year : (string) $year;
    }
}
