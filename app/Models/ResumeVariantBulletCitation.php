<?php

namespace App\Models;

use App\Exceptions\ImmutableResumeVariantSnapshotException;
use Database\Factories\ResumeVariantBulletCitationFactory;
use Illuminate\Database\Eloquent\Attributes\Boot;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One CareerFact cited as factual support for one generated
 * ResumeVariantExperienceBullet. The FK reference IS the evidence —
 * there is no separate rationale/commentary field here, unlike
 * CareerFactMatch: a resume bullet's own generated text already is the
 * employer-facing claim, so no additional internal commentary layer is
 * needed. Deliberately does NOT cascade-delete when its CareerFact is
 * deleted (`career_fact_id` is a restricted foreign key) — this row is
 * part of an immutable historical result, not a cache of live data.
 *
 * A CareerFact may legitimately back more than one bullet (or the
 * summary) when it supports genuinely distinct claims — see
 * docs/domain-model.md "ResumeVariant" anti-redundancy rules. Only
 * exact-duplicate bullet-group evidence sets are rejected, deterministically,
 * at generation time.
 *
 * @property int $id
 * @property int $resume_variant_experience_bullet_id
 * @property int $career_fact_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'resume_variant_experience_bullet_id',
    'career_fact_id',
])]
class ResumeVariantBulletCitation extends Model
{
    /** @use HasFactory<ResumeVariantBulletCitationFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<ResumeVariantExperienceBullet, $this>
     */
    public function bullet(): BelongsTo
    {
        return $this->belongsTo(ResumeVariantExperienceBullet::class, 'resume_variant_experience_bullet_id');
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
                'ResumeVariantBulletCitation rows are immutable once created — create a new ResumeVariant snapshot instead of updating an existing citation.'
            );
        });
    }
}
