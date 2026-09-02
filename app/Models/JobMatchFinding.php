<?php

namespace App\Models;

use App\Enums\JobMatchCoverage;
use App\Exceptions\ImmutableJobMatchSnapshotException;
use Database\Factories\JobMatchFindingFactory;
use Illuminate\Database\Eloquent\Attributes\Boot;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * The aggregate matching result for exactly one JobAnalysisFinding,
 * within exactly one JobMatch run. Always exists — one row per
 * JobAnalysisFinding belonging to the matched JobAnalysis, whether or
 * not any candidate support was found, so a finding with no support is
 * a deliberate, visible `coverage` classification rather than a silent
 * absence indistinguishable from "the model forgot this one."
 *
 * `coverage` is the model's holistic judgment about the finding as a
 * whole — genuinely different information from any single
 * CareerFactMatch/EducationMatch's own `relationship`, since a finding
 * can be `supported` by several `contextual`-only references jointly
 * even though none alone would justify it. See
 * docs/job-match-contract.md for full semantics, especially the
 * `no_evidence` vs `not_assessable` distinction — neither is ever a
 * claim about the candidate's actual capability.
 *
 * Belongs to exactly one JobMatch and is immutable for the same reason
 * as JobAnalysisFinding: a correction means a new JobMatch, never an
 * edit to an existing finding result.
 *
 * @property int $id
 * @property int $job_match_id
 * @property int $job_analysis_finding_id
 * @property JobMatchCoverage $coverage
 * @property string|null $coverage_rationale
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'job_match_id',
    'job_analysis_finding_id',
    'coverage',
    'coverage_rationale',
])]
class JobMatchFinding extends Model
{
    /** @use HasFactory<JobMatchFindingFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'coverage' => JobMatchCoverage::class,
        ];
    }

    /**
     * @return BelongsTo<JobMatch, $this>
     */
    public function jobMatch(): BelongsTo
    {
        return $this->belongsTo(JobMatch::class);
    }

    /**
     * @return BelongsTo<JobAnalysisFinding, $this>
     */
    public function jobAnalysisFinding(): BelongsTo
    {
        return $this->belongsTo(JobAnalysisFinding::class);
    }

    /**
     * @return HasMany<CareerFactMatch, $this>
     */
    public function careerFactMatches(): HasMany
    {
        return $this->hasMany(CareerFactMatch::class);
    }

    /**
     * @return HasMany<EducationMatch, $this>
     */
    public function educationMatches(): HasMany
    {
        return $this->hasMany(EducationMatch::class);
    }

    /**
     * Mirrors JobAnalysisFinding::preventUpdates() — only fires on
     * updates to an already-persisted row, not on initial creation, so
     * building a snapshot's findings (and their support references) is
     * unaffected.
     */
    #[Boot]
    protected static function preventUpdates(): void
    {
        static::updating(function (self $finding) {
            throw new ImmutableJobMatchSnapshotException(
                'JobMatchFinding rows are immutable once created — create a new JobMatch snapshot instead of updating an existing finding result.'
            );
        });
    }
}
