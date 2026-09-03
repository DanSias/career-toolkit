<?php

namespace App\Models;

use App\Exceptions\ImmutableResumeVariantSnapshotException;
use Database\Factories\ResumeVariantExperienceBulletFactory;
use Illuminate\Database\Eloquent\Attributes\Boot;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One generated Experience-section bullet, nested under a real,
 * canonical Employer/Role (and optionally Project) — v1 always renders
 * professional-project work as bullets under its owning role rather
 * than promoting it to a separate Selected Projects section, to avoid
 * professional work reading like a side project. See
 * docs/domain-model.md "ResumeVariant".
 *
 * `text` is Stage 2's free-generated prose. It is deliberately never
 * the sole record of what evidence backs it — see
 * ResumeVariantBulletCitation, a real relational join, not a JSON
 * array, for the same reason CareerFactMatch is a real table: FK-level
 * restrict protection only has teeth against a real foreign key.
 *
 * @property int $id
 * @property int $resume_variant_id
 * @property int $employer_id
 * @property int $role_id
 * @property int|null $project_id
 * @property string $display_title
 * @property int $display_order
 * @property string $text
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'resume_variant_id',
    'employer_id',
    'role_id',
    'project_id',
    'display_title',
    'display_order',
    'text',
])]
class ResumeVariantExperienceBullet extends Model
{
    /** @use HasFactory<ResumeVariantExperienceBulletFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<ResumeVariant, $this>
     */
    public function resumeVariant(): BelongsTo
    {
        return $this->belongsTo(ResumeVariant::class);
    }

    /**
     * @return BelongsTo<Employer, $this>
     */
    public function employer(): BelongsTo
    {
        return $this->belongsTo(Employer::class);
    }

    /**
     * @return BelongsTo<Role, $this>
     */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return HasMany<ResumeVariantBulletCitation, $this>
     */
    public function citations(): HasMany
    {
        return $this->hasMany(ResumeVariantBulletCitation::class);
    }

    /**
     * @return HasMany<ResumeVariantTargetTermUsage, $this>
     */
    public function targetTermUsages(): HasMany
    {
        return $this->hasMany(ResumeVariantTargetTermUsage::class, 'bullet_id');
    }

    /**
     * Mirrors ResumeVariant::preventUpdates() — only fires on updates
     * to an already-persisted row, not on initial creation, so
     * constructing a snapshot's bullets (and their citations) is
     * unaffected.
     */
    #[Boot]
    protected static function preventUpdates(): void
    {
        static::updating(function (self $bullet) {
            throw new ImmutableResumeVariantSnapshotException(
                'ResumeVariantExperienceBullet rows are immutable once created — create a new ResumeVariant snapshot instead of updating an existing bullet.'
            );
        });
    }
}
