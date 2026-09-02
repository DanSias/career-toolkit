<?php

use App\Enums\MetricComparator;
use App\Models\Metric;
use App\Support\CareerData\MetricFormatter;

function makeMetric(array $attributes): Metric
{
    return new Metric(array_merge([
        'value_max' => null,
        'comparator' => MetricComparator::Exact,
    ], $attributes));
}

it('formats a plain percentage', function () {
    $metric = makeMetric(['value' => 85, 'unit' => 'percent_reduction']);

    expect(MetricFormatter::format($metric))->toBe('85%');
});

it('formats a decimal percentage without a trailing zero', function () {
    $metric = makeMetric(['value' => 201.2, 'unit' => 'percent_increase']);

    expect(MetricFormatter::format($metric))->toBe('201.2%');
});

it('formats a large at-least USD value with M abbreviation', function () {
    $metric = makeMetric(['value' => 25_000_000, 'unit' => 'usd', 'comparator' => MetricComparator::AtLeast]);

    expect(MetricFormatter::format($metric))->toBe('$25M+');
});

it('formats a sub-million exact USD value with M abbreviation', function () {
    $metric = makeMetric(['value' => 1_300_000, 'unit' => 'usd']);

    expect(MetricFormatter::format($metric))->toBe('$1.3M');
});

it('formats an at-least count with a unit suffix', function () {
    $metric = makeMetric(['value' => 20, 'unit' => 'hours_per_week', 'comparator' => MetricComparator::AtLeast]);

    expect(MetricFormatter::format($metric))->toBe('20+ hours/week');
});

it('formats a bounded range without collapsing it into a single value', function () {
    $metric = makeMetric([
        'value' => 10, 'value_max' => 15, 'unit' => 'hours_per_month', 'comparator' => MetricComparator::Approximately,
    ]);

    expect(MetricFormatter::format($metric))->toBe('approximately 10–15 hours/month')
        ->and($metric->isRange())->toBeTrue();
});

it('formats a large transaction count with commas and a known unit label', function () {
    $metric = makeMetric(['value' => 32_000, 'unit' => 'transactions_corrected', 'comparator' => MetricComparator::AtLeast]);

    expect(MetricFormatter::format($metric))->toBe('32,000+ transactions');
});

it('falls back to a humanized unit label for an unknown unit', function () {
    $metric = makeMetric(['value' => 5, 'unit' => 'code_reviews_completed']);

    expect(MetricFormatter::format($metric))->toBe('5 code reviews completed');
});
