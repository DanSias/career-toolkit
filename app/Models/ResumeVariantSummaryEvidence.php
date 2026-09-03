<?php

namespace App\Models;

use App\Exceptions\ImmutableResumeVariantSnapshotException;
use Database\Factories\ResumeVariantSummaryEvidenceFactory;
use Illuminate\Database\Eloquent\Attributes\Boot;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One CareerFact cited as factual support for the ResumeVariant's
 * generated summary text — the same protective lineage guarantee a
 * bullet's citations have. Restricted, not cascading, on career_fact_id.
 *
 * @property int $id
 * @property int $resume_variant_id
 * @property int $career_fact_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'resume_variant_id',
    'career_fact_id',
])]
class ResumeVariantSummaryEvidence extends Model
{
    /** @use HasFactory<ResumeVariantSummaryEvidenceFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<ResumeVariant, $this>
     */
    public function resumeVariant(): BelongsTo
    {
        return $this->belongsTo(ResumeVariant::class);
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
                'ResumeVariantSummaryEvidence rows are immutable once created — create a new ResumeVariant snapshot instead of updating an existing row.'
            );
        });
    }
}
