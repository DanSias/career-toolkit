<?php

namespace App\Models;

use App\Enums\MetricComparator;
use App\Exceptions\InvalidMetricRangeException;
use Database\Factories\MetricFactory;
use Illuminate\Database\Eloquent\Attributes\Boot;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Structured quantitative data owned by exactly one CareerFact. Not a
 * standalone top-level entity — see docs/domain-model.md for why.
 *
 * `value` is always the primary/lower figure. `value_max` is null for a
 * single-ended value (e.g. "85%", "$25M+", "20+ hours/week") and set only
 * for a genuine bounded range (e.g. "approximately 10-15 hours/month" is
 * value=10, value_max=15) — never a fabricated midpoint. `comparator`
 * still applies to the whole figure (e.g. `approximately` on a range
 * means "approximately this range", not a fuzzy single point).
 *
 * @property int $id
 * @property int $career_fact_id
 * @property string $value
 * @property string|null $value_max
 * @property string $unit
 * @property MetricComparator|null $comparator
 * @property string|null $scope_note
 * @property string|null $guardrail
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Table('career_fact_metrics')]
#[Fillable(['career_fact_id', 'value', 'value_max', 'unit', 'comparator', 'scope_note', 'guardrail'])]
class Metric extends Model
{
    /** @use HasFactory<MetricFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'value' => 'decimal:2',
            'value_max' => 'decimal:2',
            'comparator' => MetricComparator::class,
        ];
    }

    /**
     * @return BelongsTo<CareerFact, $this>
     */
    public function careerFact(): BelongsTo
    {
        return $this->belongsTo(CareerFact::class);
    }

    /**
     * @return bool True when this Metric represents a bounded range
     *              rather than a single-ended value.
     */
    public function isRange(): bool
    {
        return $this->value_max !== null;
    }

    /**
     * Enforces the one range invariant: `value_max`, when present, must
     * not be below `value`. Runs on every save via the `saving` event,
     * same convention as CareerFact's attribution check and Role's date
     * check.
     */
    #[Boot]
    protected static function enforceValidRange(): void
    {
        static::saving(function (self $metric) {
            $metric->assertValidRange();
        });
    }

    protected function assertValidRange(): void
    {
        if ($this->value_max !== null && (float) $this->value_max < (float) $this->value) {
            throw new InvalidMetricRangeException(
                "Metric value_max ({$this->value_max}) cannot be less than value ({$this->value})."
            );
        }
    }
}
