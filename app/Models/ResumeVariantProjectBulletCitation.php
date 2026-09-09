<?php

namespace App\Models;

use App\Exceptions\ImmutableResumeVariantSnapshotException;
use Database\Factories\ResumeVariantProjectBulletCitationFactory;
use Illuminate\Database\Eloquent\Attributes\Boot;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One CareerFact cited as factual support for one generated
 * ResumeVariantProjectBullet. Mirrors ResumeVariantBulletCitation
 * exactly, including the restricted (never cascading) career_fact_id
 * FK — this row is part of an immutable historical result, not a cache
 * of live data.
 *
 * @property int $id
 * @property int $resume_variant_project_bullet_id
 * @property int $career_fact_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'resume_variant_project_bullet_id',
    'career_fact_id',
])]
class ResumeVariantProjectBulletCitation extends Model
{
    /** @use HasFactory<ResumeVariantProjectBulletCitationFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<ResumeVariantProjectBullet, $this>
     */
    public function bullet(): BelongsTo
    {
        return $this->belongsTo(ResumeVariantProjectBullet::class, 'resume_variant_project_bullet_id');
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
        static::updating(function (self $citation) {
            throw new ImmutableResumeVariantSnapshotException(
                'ResumeVariantProjectBulletCitation rows are immutable once created — create a new ResumeVariant snapshot instead of updating an existing citation.'
            );
        });
    }
}
