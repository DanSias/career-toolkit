<?php

namespace App\Models;

use App\Exceptions\ImmutableResumeVariantSnapshotException;
use Database\Factories\ResumeVariantSkillSelectionFactory;
use Illuminate\Database\Eloquent\Attributes\Boot;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One canonical Skill selected for the Skills section. `name`/
 * `category` are frozen from the real, canonical Skill at generation
 * time — never generated text, and never re-resolved from the live
 * Skill on render, so a later rename/recategorization can't change
 * what an already-generated ResumeVariant is understood to say. The
 * `skill_id` FK (restrictOnDelete) remains for lineage/audit only.
 * Skills are a selection-and-ordering problem, not a wording problem:
 * no qualified target-term positioning is permitted here (see
 * docs/domain-model.md "ResumeVariant") — a skill either has real,
 * eligible CareerFact backing somewhere in the profile, or it isn't
 * listed.
 *
 * @property int $id
 * @property int $resume_variant_id
 * @property int $skill_id
 * @property string $name
 * @property string $category
 * @property int $display_order
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'resume_variant_id',
    'skill_id',
    'name',
    'category',
    'display_order',
])]
class ResumeVariantSkillSelection extends Model
{
    /** @use HasFactory<ResumeVariantSkillSelectionFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<ResumeVariant, $this>
     */
    public function resumeVariant(): BelongsTo
    {
        return $this->belongsTo(ResumeVariant::class);
    }

    /**
     * @return BelongsTo<Skill, $this>
     */
    public function skill(): BelongsTo
    {
        return $this->belongsTo(Skill::class);
    }

    #[Boot]
    protected static function preventUpdates(): void
    {
        static::updating(function (self $selection) {
            throw new ImmutableResumeVariantSnapshotException(
                'ResumeVariantSkillSelection rows are immutable once created — create a new ResumeVariant snapshot instead of updating an existing selection.'
            );
        });
    }
}
