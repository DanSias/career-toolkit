<?php

namespace App\Models;

use App\Exceptions\ImmutableResumeVariantSnapshotException;
use Database\Factories\ResumeVariantTargetTermUsageEvidenceFactory;
use Illuminate\Database\Eloquent\Attributes\Boot;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One CareerFact supporting a specific ResumeVariantTargetTermUsage's
 * claim. For a `qualified` usage, the "actual technologies" a rendered
 * clause names are derived from these citations' attached canonical
 * Skills at render time — never a separately declared/validated string
 * field — so the rendered comparison is correct by construction rather
 * than asserted-then-checked. Restricted, not cascading, on
 * career_fact_id, same protective reasoning as every other
 * canonical-evidence reference in this snapshot.
 *
 * @property int $id
 * @property int $resume_variant_target_term_usage_id
 * @property int $career_fact_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'resume_variant_target_term_usage_id',
    'career_fact_id',
])]
class ResumeVariantTargetTermUsageEvidence extends Model
{
    /** @use HasFactory<ResumeVariantTargetTermUsageEvidenceFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<ResumeVariantTargetTermUsage, $this>
     */
    public function usage(): BelongsTo
    {
        return $this->belongsTo(ResumeVariantTargetTermUsage::class, 'resume_variant_target_term_usage_id');
    }

    /**
     * @return BelongsTo<CareerFact, $this>
     */
    public function careerFact(): BelongsTo
    {
        return $this->belongsTo(CareerFact::class);
    }

    #[Boot]
    protected static function preventUpdates(): void
    {
        static::updating(function (self $evidence) {
            throw new ImmutableResumeVariantSnapshotException(
                'ResumeVariantTargetTermUsageEvidence rows are immutable once created — create a new ResumeVariant snapshot instead of updating an existing row.'
            );
        });
    }
}
