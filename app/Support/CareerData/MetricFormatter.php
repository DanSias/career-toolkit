<?php

namespace App\Support\CareerData;

use App\Enums\MetricComparator;
use App\Models\Metric;

/**
 * Renders a Metric's value/value_max/unit/comparator as one natural,
 * human-readable string — "85%", "$25M+", "20+ hours/week", "approximately
 * 10–15 hours/month", "32,000+ transactions" — without ever collapsing a
 * bounded range into a single number.
 */
final class MetricFormatter
{
    /**
     * Units with no natural symbol get a readable suffix appended.
     * Anything not listed here falls back to the unit string itself with
     * underscores replaced by spaces — units are intentionally
     * open-ended (see docs/domain-model.md), so this list is not
     * exhaustive by design.
     */
    private const UNIT_SUFFIXES = [
        'hours_per_week' => 'hours/week',
        'hours_per_month' => 'hours/month',
        'transactions_corrected' => 'transactions',
        'transactions_analyzed' => 'transactions analyzed',
        'transactions_in_desired_state' => 'transactions in desired state',
        'transactions_initially_identified' => 'transactions identified',
    ];

    public static function format(Metric $metric): string
    {
        $isUsd = $metric->unit === 'usd';
        $isPercent = str_starts_with($metric->unit, 'percent');

        $number = $metric->isRange()
            ? self::formatSingle((float) $metric->value, $isUsd).'–'.self::formatSingle((float) $metric->value_max, $isUsd)
            : self::formatSingle((float) $metric->value, $isUsd).self::atLeastSuffix($metric);

        if ($metric->comparator === MetricComparator::Approximately) {
            $number = 'approximately '.$number;
        }

        if ($isPercent) {
            return $number.'%';
        }

        if ($isUsd) {
            return $number;
        }

        $suffix = self::UNIT_SUFFIXES[$metric->unit] ?? str_replace('_', ' ', $metric->unit);

        return trim($number.' '.$suffix);
    }

    private static function atLeastSuffix(Metric $metric): string
    {
        return ! $metric->isRange() && $metric->comparator === MetricComparator::AtLeast ? '+' : '';
    }

    private static function formatSingle(float $value, bool $isUsd): string
    {
        if (! $isUsd) {
            return number_format($value, self::hasFraction($value) ? 1 : 0);
        }

        return match (true) {
            $value >= 1_000_000 => '$'.self::trimmedDecimal($value / 1_000_000).'M',
            $value >= 1_000 => '$'.self::trimmedDecimal($value / 1_000).'K',
            default => '$'.self::trimmedDecimal($value),
        };
    }

    private static function trimmedDecimal(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2), '0'), '.');
    }

    private static function hasFraction(float $value): bool
    {
        return $value != floor($value);
    }
}
