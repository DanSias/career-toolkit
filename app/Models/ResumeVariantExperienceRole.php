<?php

namespace App\Models;

use App\Exceptions\ImmutableResumeVariantSnapshotException;
use Database\Factories\ResumeVariantExperienceRoleFactory;
use Illuminate\Database\Eloquent\Attributes\Boot;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One frozen Role-level snapshot within a ResumeVariant: which real
 * canonical Role this is (lineage only, restrictOnDelete), plus the
 * employer name, resolved display title, and dates exactly as they
 * were at generation time — never re-resolved from the live Role/
 * Employer on render. See docs/domain-model.md "ResumeVariant" ->
 * "Experience role snapshots" for why this is a first-class row
 * rather than duplicated per bullet.
 *
 * @property int $id
 * @property int $resume_variant_id
 * @property int $role_id
 * @property string $employer_name
 * @property string $display_title
 * @property int $start_year
 * @property int|null $start_month
 * @property int|null $end_year
 * @property int|null $end_month
 * @property int $display_order
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'resume_variant_id',
    'role_id',
    'employer_name',
    'display_title',
    'start_year',
    'start_month',
    'end_year',
    'end_month',
    'display_order',
])]
class ResumeVariantExperienceRole extends Model
{
    /** @use HasFactory<ResumeVariantExperienceRoleFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<ResumeVariant, $this>
     */
    public function resumeVariant(): BelongsTo
    {
        return $this->belongsTo(ResumeVariant::class);
    }

    /**
     * Lineage only — never read for display; see class docblock.
     *
     * @return BelongsTo<Role, $this>
     */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    /**
     * @return HasMany<ResumeVariantExperienceBullet, $this>
     */
    public function bullets(): HasMany
    {
        return $this->hasMany(ResumeVariantExperienceBullet::class);
    }

    /**
     * Mirrors ResumeVariant::preventUpdates() exactly.
     */
    #[Boot]
    protected static function preventUpdates(): void
    {
        static::updating(function (self $role) {
            throw new ImmutableResumeVariantSnapshotException(
                'ResumeVariantExperienceRole rows are immutable once created — create a new ResumeVariant snapshot instead of updating an existing one.'
            );
        });
    }
}
