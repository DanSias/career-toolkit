<?php

use App\Enums\MetricComparator;
use App\Exceptions\InvalidMetricRangeException;
use App\Models\CareerFact;
use App\Models\Metric;

it('persists a single-ended metric with no value_max', function () {
    $metric = Metric::factory()->create(['value' => 85, 'value_max' => null, 'unit' => 'percent_reduction']);

    expect($metric->exists)->toBeTrue()
        ->and($metric->value_max)->toBeNull()
        ->and($metric->isRange())->toBeFalse();
});

it('persists a genuine bounded range without inventing a midpoint', function () {
    $fact = CareerFact::factory()->create();

    $metric = Metric::factory()->for($fact)->ranged(10, 15)->create([
        'unit' => 'hours_per_month',
    ]);

    expect($metric->fresh()->value)->toEqual('10.00')
        ->and($metric->fresh()->value_max)->toEqual('15.00')
        ->and($metric->fresh()->comparator)->toBe(MetricComparator::Approximately)
        ->and($metric->fresh()->isRange())->toBeTrue();
});

it('rejects a range where value_max is less than value', function () {
    expect(fn () => Metric::factory()->create(['value' => 15, 'value_max' => 10]))
        ->toThrow(InvalidMetricRangeException::class);
});

it('allows value_max equal to value', function () {
    $metric = Metric::factory()->create(['value' => 10, 'value_max' => 10]);

    expect($metric->exists)->toBeTrue();
});

it('rejects changing an existing valid metric to an invalid range on update', function () {
    $metric = Metric::factory()->create(['value' => 10, 'value_max' => 15]);

    expect(fn () => $metric->update(['value_max' => 5]))
        ->toThrow(InvalidMetricRangeException::class);

    expect($metric->fresh()->value_max)->toEqual('15.00');
});
