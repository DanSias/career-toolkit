<?php

namespace App\Models;

use App\Exceptions\ImmutableResumeVariantSnapshotException;
use Database\Factories\ResumeVariantProjectFactory;
use Illuminate\Database\Eloquent\Attributes\Boot;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One frozen Selected-Projects entry: which real, independent
 * (`role_id IS NULL`) canonical Project this is (lineage only,
 * restrictOnDelete), plus its name, cited technology names, and
 * live/repository URLs exactly as they were at generation time — never
 * re-resolved from the live Project/Skill on render. See
 * docs/domain-model.md "ResumeVariant" -> "Selected Projects".
 *
 * @property int $id
 * @property int $resume_variant_id
 * @property int $project_id
 * @property string $name
 * @property array<int, string> $technology_names
 * @property string|null $live_url
 * @property string|null $repository_url
 * @property int $display_order
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'resume_variant_id',
    'project_id',
    'name',
    'technology_names',
    'live_url',
    'repository_url',
    'display_order',
])]
class ResumeVariantProject extends Model
{
    /** @use HasFactory<ResumeVariantProjectFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'technology_names' => 'array',
        ];
    }

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
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return HasMany<ResumeVariantProjectBullet, $this>
     */
    public function bullets(): HasMany
    {
        return $this->hasMany(ResumeVariantProjectBullet::class);
    }

    /**
     * Mirrors ResumeVariant::preventUpdates() exactly.
     */
    #[Boot]
    protected static function preventUpdates(): void
    {
        static::updating(function (self $project) {
            throw new ImmutableResumeVariantSnapshotException(
                'ResumeVariantProject rows are immutable once created — create a new ResumeVariant snapshot instead of updating an existing one.'
            );
        });
    }
}
