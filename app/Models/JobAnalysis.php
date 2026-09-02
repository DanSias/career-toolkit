<?php

namespace App\Models;

use App\Enums\JobAnalysisSeniority;
use App\Exceptions\ImmutableJobAnalysisSnapshotException;
use Database\Factories\JobAnalysisFactory;
use Illuminate\Database\Eloquent\Attributes\Boot;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * An immutable, successfully validated analysis snapshot derived from one
 * JobPosting: what the employer appears to be asking for. Never
 * represents an in-progress or failed generation attempt — a row only
 * exists once analysis has succeeded, so there is no `status` column.
 * Generation-attempt tracking (retries, malformed output, failures) is
 * deliberately deferred to whenever the LLM integration is actually
 * built, not modeled speculatively here. See docs/domain-model.md
 * "JobAnalysis" and docs/job-analysis-contract.md for the structured
 * shape this maps to.
 *
 * Deliberately holds no relationship to CareerFact, Skill, Project,
 * Employer, or Role, and never will at this layer — see
 * docs/domain-model.md's candidate-independence boundary.
 *
 * @property int $id
 * @property int $job_posting_id
 * @property string $schema_version
 * @property string|null $prompt_version
 * @property string|null $generated_by
 * @property Carbon $generated_at
 * @property array<string, mixed>|null $raw_response
 * @property string $role_summary
 * @property JobAnalysisSeniority|null $overall_seniority
 * @property string|null $seniority_rationale
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read int|null $findings_count Only present when loaded via withCount('findings').
 */
#[Fillable([
    'job_posting_id',
    'schema_version',
    'prompt_version',
    'generated_by',
    'generated_at',
    'raw_response',
    'role_summary',
    'overall_seniority',
    'seniority_rationale',
])]
class JobAnalysis extends Model
{
    /** @use HasFactory<JobAnalysisFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'generated_at' => 'datetime',
            'raw_response' => 'array',
            'overall_seniority' => JobAnalysisSeniority::class,
        ];
    }

    /**
     * @return BelongsTo<JobPosting, $this>
     */
    public function jobPosting(): BelongsTo
    {
        return $this->belongsTo(JobPosting::class);
    }

    /**
     * @return HasMany<JobAnalysisFinding, $this>
     */
    public function findings(): HasMany
    {
        return $this->hasMany(JobAnalysisFinding::class);
    }

    /**
     * A JobAnalysis is a frozen snapshot: once created, it is never
     * updated — a correction or re-analysis creates a new JobAnalysis
     * row instead. This only guards against updating an already-persisted
     * row; it does not fire while the snapshot (and its findings /
     * evidence) is first being constructed, since creating child rows
     * never updates this row.
     */
    #[Boot]
    protected static function preventUpdates(): void
    {
        static::updating(function (self $analysis) {
            throw new ImmutableJobAnalysisSnapshotException(
                'JobAnalysis snapshots are immutable once created — create a new JobAnalysis instead of updating an existing one.'
            );
        });
    }
}
