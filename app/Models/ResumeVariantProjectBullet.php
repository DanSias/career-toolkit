<?php

namespace App\Models;

use App\Exceptions\ImmutableResumeVariantSnapshotException;
use Database\Factories\ResumeVariantProjectBulletFactory;
use Illuminate\Database\Eloquent\Attributes\Boot;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One generated Selected-Projects bullet, nested under a frozen
 * ResumeVariantProject snapshot. `text` is Stage 2's free-generated
 * prose, never the sole record of what evidence backs it — see
 * ResumeVariantProjectBulletCitation, a real relational join, not a
 * JSON array, for the same reason ResumeVariantBulletCitation exists
 * for Experience bullets.
 *
 * v1 persists exactly one of these per ResumeVariantProject, but
 * nothing here enforces that cap — it lives in the Selection/Wording
 * schema and validators, not the schema. See that migration's own
 * docblock.
 *
 * @property int $id
 * @property int $resume_variant_project_id
 * @property int $display_order
 * @property string $text
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'resume_variant_project_id',
    'display_order',
    'text',
])]
class ResumeVariantProjectBullet extends Model
{
    /** @use HasFactory<ResumeVariantProjectBulletFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<ResumeVariantProject, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(ResumeVariantProject::class, 'resume_variant_project_id');
    }

    /**
     * @return HasMany<ResumeVariantProjectBulletCitation, $this>
     */
    public function citations(): HasMany
    {
        return $this->hasMany(ResumeVariantProjectBulletCitation::class);
    }

    /**
     * Mirrors ResumeVariantExperienceBullet::preventUpdates() exactly.
     */
    #[Boot]
    protected static function preventUpdates(): void
    {
        static::updating(function (self $bullet) {
            throw new ImmutableResumeVariantSnapshotException(
                'ResumeVariantProjectBullet rows are immutable once created — create a new ResumeVariant snapshot instead of updating an existing bullet.'
            );
        });
    }
}
