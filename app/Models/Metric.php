<?php

namespace App\Models;

use App\Enums\MetricComparator;
use Database\Factories\MetricFactory;
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
 * @property int $id
 * @property int $career_fact_id
 * @property string $value
 * @property string $unit
 * @property MetricComparator|null $comparator
 * @property string|null $scope_note
 * @property string|null $guardrail
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Table('career_fact_metrics')]
#[Fillable(['career_fact_id', 'value', 'unit', 'comparator', 'scope_note', 'guardrail'])]
class Metric extends Model
{
    /** @use HasFactory<MetricFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'value' => 'decimal:2',
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
}
