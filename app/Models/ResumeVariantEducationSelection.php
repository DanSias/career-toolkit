<?php

namespace App\Models;

use App\Exceptions\ImmutableResumeVariantSnapshotException;
use Database\Factories\ResumeVariantEducationSelectionFactory;
use Illuminate\Database\Eloquent\Attributes\Boot;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One Education record selected for the Education section — rendered
 * directly from canonical institution/degree/field/date fields, with no
 * generated wording at all. Education is not subject to
 * CareerFact.visibility (no such field exists on it), and its absence
 * of that restriction is not a grant of default inclusion — selection
 * is still a deliberate Stage-1 decision on its own merits. See
 * docs/domain-model.md "ResumeVariant".
 *
 * @property int $id
 * @property int $resume_variant_id
 * @property int $education_id
 * @property int $display_order
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'resume_variant_id',
    'education_id',
    'display_order',
])]
class ResumeVariantEducationSelection extends Model
{
    /** @use HasFactory<ResumeVariantEducationSelectionFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<ResumeVariant, $this>
     */
    public function resumeVariant(): BelongsTo
    {
        return $this->belongsTo(ResumeVariant::class);
    }

    /**
     * @return BelongsTo<Education, $this>
     */
    public function education(): BelongsTo
    {
        return $this->belongsTo(Education::class);
    }

    #[Boot]
    protected static function preventUpdates(): void
    {
        static::updating(function (self $selection) {
            throw new ImmutableResumeVariantSnapshotException(
                'ResumeVariantEducationSelection rows are immutable once created — create a new ResumeVariant snapshot instead of updating an existing selection.'
            );
        });
    }
}
