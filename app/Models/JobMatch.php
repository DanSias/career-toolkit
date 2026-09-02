<?php

namespace App\Models;

use App\Exceptions\ImmutableJobMatchSnapshotException;
use Database\Factories\JobMatchFactory;
use Illuminate\Database\Eloquent\Attributes\Boot;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * An immutable, successfully validated snapshot of one matching run:
 * which verified CareerFacts and Education records support which
 * findings of one JobAnalysis, for one CareerProfile. Never represents
 * an in-progress or failed generation attempt — a row only exists once
 * matching has succeeded, so there is no `status` column, mirroring
 * JobAnalysis's own reasoning exactly. See docs/domain-model.md
 * "JobMatch" and docs/job-match-contract.md for the structured shape
 * this maps to.
 *
 * Unlike JobAnalysis generation, this stage deliberately DOES consume
 * candidate data (CareerFact, Education) — that's its entire purpose.
 * See docs/job-match-generation.md for the candidate-independence
 * boundary this stage intentionally does NOT have.
 *
 * `input_snapshot` freezes the exact normalized candidate+job payload
 * this run was based on, so a later edit to a live CareerFact,
 * Education row, or JobAnalysisFinding can never retroactively change
 * what this snapshot is understood to have seen. `raw_response` is the
 * separate, complete validated structured output. See
 * docs/job-match-generation.md for why both exist and what each
 * answers.
 *
 * @property int $id
 * @property int $job_analysis_id
 * @property int $career_profile_id
 * @property string $schema_version
 * @property string|null $prompt_version
 * @property string|null $generated_by
 * @property Carbon $generated_at
 * @property array<string, mixed> $input_snapshot
 * @property array<string, mixed>|null $raw_response
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read int|null $findings_count Only present when loaded via withCount('findings').
 */
#[Fillable([
    'job_analysis_id',
    'career_profile_id',
    'schema_version',
    'prompt_version',
    'generated_by',
    'generated_at',
    'input_snapshot',
    'raw_response',
])]
class JobMatch extends Model
{
    /** @use HasFactory<JobMatchFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'generated_at' => 'datetime',
            'input_snapshot' => 'array',
            'raw_response' => 'array',
        ];
    }

    /**
     * @return BelongsTo<JobAnalysis, $this>
     */
    public function jobAnalysis(): BelongsTo
    {
        return $this->belongsTo(JobAnalysis::class);
    }

    /**
     * @return BelongsTo<CareerProfile, $this>
     */
    public function careerProfile(): BelongsTo
    {
        return $this->belongsTo(CareerProfile::class);
    }

    /**
     * @return HasMany<JobMatchFinding, $this>
     */
    public function findings(): HasMany
    {
        return $this->hasMany(JobMatchFinding::class);
    }

    /**
     * A JobMatch is a frozen snapshot: once created, it is never
     * updated — a correction or re-run creates a new JobMatch row
     * instead. Only guards against updating an already-persisted row;
     * does not fire while the snapshot (and its findings/matches) is
     * first being constructed, since creating child rows never updates
     * this row. Mirrors JobAnalysis::preventUpdates() exactly.
     */
    #[Boot]
    protected static function preventUpdates(): void
    {
        static::updating(function (self $match) {
            throw new ImmutableJobMatchSnapshotException(
                'JobMatch snapshots are immutable once created — create a new JobMatch instead of updating an existing one.'
            );
        });
    }
}
