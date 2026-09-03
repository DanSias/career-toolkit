<?php

namespace App\Models;

use App\Enums\ResumeClaimPosture;
use App\Enums\ResumeQualifiedPhrase;
use App\Enums\ResumeTermUsageLocation;
use App\Exceptions\ImmutableResumeVariantSnapshotException;
use Database\Factories\ResumeVariantTargetTermUsageFactory;
use Illuminate\Database\Eloquent\Attributes\Boot;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One deliberate decision about how a specific job-relevant target
 * term (e.g. "Azure") is positioned in this ResumeVariant — attached to
 * either one bullet or the summary, never to an entire bullet
 * indiscriminately and never to every citation on it. A single bullet
 * may have several `direct`/`capability` usages plus at most one
 * `qualified` usage (enforced deterministically, see
 * ResumeVariantGenerationValidator).
 *
 * `posture` (ResumeClaimPosture) is always persisted explicitly,
 * including `capability` — never inferred from the absence of a row —
 * so the ATS-terminology review can distinguish "deliberately
 * represented at the capability level" from "not addressed at all."
 *
 * `relationship_phrase_key` is always present (the `not_applicable`
 * sentinel when posture isn't `qualified`), matching this codebase's
 * established avoidance of nullable+enum fields. The literal target
 * term may appear in generated output ONLY when posture is `qualified`,
 * and only through the deterministically-rendered clause built from
 * this row plus its evidence pivot — never in Stage 2's own free prose.
 * See docs/resume-variant-contract.md.
 *
 * @property int $id
 * @property int $resume_variant_id
 * @property int|null $bullet_id
 * @property int $job_analysis_finding_id
 * @property string $target_term
 * @property ResumeClaimPosture $posture
 * @property ResumeTermUsageLocation $location
 * @property ResumeQualifiedPhrase $relationship_phrase_key
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'resume_variant_id',
    'bullet_id',
    'job_analysis_finding_id',
    'target_term',
    'posture',
    'location',
    'relationship_phrase_key',
])]
class ResumeVariantTargetTermUsage extends Model
{
    /** @use HasFactory<ResumeVariantTargetTermUsageFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'posture' => ResumeClaimPosture::class,
            'location' => ResumeTermUsageLocation::class,
            'relationship_phrase_key' => ResumeQualifiedPhrase::class,
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
     * @return BelongsTo<ResumeVariantExperienceBullet, $this>
     */
    public function bullet(): BelongsTo
    {
        return $this->belongsTo(ResumeVariantExperienceBullet::class, 'bullet_id');
    }

    /**
     * @return BelongsTo<JobAnalysisFinding, $this>
     */
    public function jobAnalysisFinding(): BelongsTo
    {
        return $this->belongsTo(JobAnalysisFinding::class);
    }

    /**
     * @return HasMany<ResumeVariantTargetTermUsageEvidence, $this>
     */
    public function evidence(): HasMany
    {
        return $this->hasMany(ResumeVariantTargetTermUsageEvidence::class, 'resume_variant_target_term_usage_id');
    }

    #[Boot]
    protected static function preventUpdates(): void
    {
        static::updating(function (self $usage) {
            throw new ImmutableResumeVariantSnapshotException(
                'ResumeVariantTargetTermUsage rows are immutable once created — create a new ResumeVariant snapshot instead of updating an existing usage.'
            );
        });
    }
}
