<?php

namespace App\Models;

use App\Exceptions\ImmutableResumeVariantSnapshotException;
use Database\Factories\ResumeVariantFactory;
use Illuminate\Database\Eloquent\Attributes\Boot;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * An immutable, successfully generated tailored-resume snapshot: which
 * canonical CareerFacts/Education/Skills were selected for one
 * JobMatch, how they were grouped and worded, and what target-term
 * positioning was used. Never represents an in-progress or failed
 * generation — a row only exists once both the Selection and Wording
 * stages have succeeded and passed deterministic validation, mirroring
 * JobAnalysis/JobMatch's own reasoning exactly (no `status` column).
 * See docs/domain-model.md "ResumeVariant" and
 * docs/resume-variant-contract.md for the structured shapes this maps
 * to.
 *
 * Two independent generation calls feed one row: Selection (structure,
 * lineage, claim posture — no prose) and Wording (prose, strictly
 * bounded to what Selection already approved). `selection_raw_response`
 * is retained separately from `wording_raw_response` specifically so a
 * future wording-only regeneration can reuse an existing, already-
 * approved selection without re-running Stage 1. `*_input_snapshot`
 * freezes exactly what each stage saw, so a later edit to a live
 * CareerFact/Education/Skill (or a later visibility change) can never
 * retroactively change what an already-persisted ResumeVariant is
 * understood to have been based on. See docs/resume-variant-generation.md.
 *
 * @property int $id
 * @property int $career_profile_id
 * @property int $job_match_id
 * @property string $schema_version
 * @property string $selection_prompt_version
 * @property string $wording_prompt_version
 * @property string|null $selection_generated_by
 * @property string|null $wording_generated_by
 * @property Carbon $generated_at
 * @property array<string, mixed> $selection_input_snapshot
 * @property array<string, mixed>|null $selection_raw_response
 * @property array<string, mixed> $wording_input_snapshot
 * @property array<string, mixed>|null $wording_raw_response
 * @property string|null $summary
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'career_profile_id',
    'job_match_id',
    'schema_version',
    'selection_prompt_version',
    'wording_prompt_version',
    'selection_generated_by',
    'wording_generated_by',
    'generated_at',
    'selection_input_snapshot',
    'selection_raw_response',
    'wording_input_snapshot',
    'wording_raw_response',
    'summary',
])]
class ResumeVariant extends Model
{
    /** @use HasFactory<ResumeVariantFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'generated_at' => 'datetime',
            'selection_input_snapshot' => 'array',
            'selection_raw_response' => 'array',
            'wording_input_snapshot' => 'array',
            'wording_raw_response' => 'array',
        ];
    }

    /**
     * @return BelongsTo<CareerProfile, $this>
     */
    public function careerProfile(): BelongsTo
    {
        return $this->belongsTo(CareerProfile::class);
    }

    /**
     * @return BelongsTo<JobMatch, $this>
     */
    public function jobMatch(): BelongsTo
    {
        return $this->belongsTo(JobMatch::class);
    }

    /**
     * @return HasMany<ResumeVariantExperienceBullet, $this>
     */
    public function experienceBullets(): HasMany
    {
        return $this->hasMany(ResumeVariantExperienceBullet::class);
    }

    /**
     * One frozen snapshot row per Role appearing in this variant,
     * ordered by the deterministic reverse-chronological rank computed
     * once at generation time. See docs/domain-model.md "ResumeVariant"
     * -> "Experience role snapshots".
     *
     * @return HasMany<ResumeVariantExperienceRole, $this>
     */
    public function experienceRoles(): HasMany
    {
        return $this->hasMany(ResumeVariantExperienceRole::class);
    }

    /**
     * @return HasMany<ResumeVariantSummaryEvidence, $this>
     */
    public function summaryEvidence(): HasMany
    {
        return $this->hasMany(ResumeVariantSummaryEvidence::class);
    }

    /**
     * @return HasMany<ResumeVariantSkillSelection, $this>
     */
    public function skillSelections(): HasMany
    {
        return $this->hasMany(ResumeVariantSkillSelection::class);
    }

    /**
     * @return HasMany<ResumeVariantEducationSelection, $this>
     */
    public function educationSelections(): HasMany
    {
        return $this->hasMany(ResumeVariantEducationSelection::class);
    }

    /**
     * Zero to three frozen Selected-Projects entries (independent
     * Projects only — see docs/domain-model.md "ResumeVariant" ->
     * "Selected Projects"), ordered by Selection's own relevance order.
     *
     * @return HasMany<ResumeVariantProject, $this>
     */
    public function projects(): HasMany
    {
        return $this->hasMany(ResumeVariantProject::class);
    }

    /**
     * @return HasMany<ResumeVariantTargetTermUsage, $this>
     */
    public function targetTermUsages(): HasMany
    {
        return $this->hasMany(ResumeVariantTargetTermUsage::class);
    }

    /**
     * A ResumeVariant is a frozen snapshot: once created, it is never
     * updated — every regeneration (full, or a future wording-only
     * regeneration) creates a new ResumeVariant row instead. Only
     * guards against updating an already-persisted row; does not fire
     * while the snapshot's own tree is first being constructed.
     * Mirrors JobMatch::preventUpdates() exactly.
     */
    #[Boot]
    protected static function preventUpdates(): void
    {
        static::updating(function (self $variant) {
            throw new ImmutableResumeVariantSnapshotException(
                'ResumeVariant snapshots are immutable once created — create a new ResumeVariant instead of updating an existing one.'
            );
        });
    }
}
