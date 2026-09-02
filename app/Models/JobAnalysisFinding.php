<?php

namespace App\Models;

use App\Enums\JobAnalysisEmphasis;
use App\Enums\JobAnalysisFindingBasis;
use App\Enums\JobAnalysisFindingCategory;
use App\Enums\JobAnalysisMaturity;
use App\Enums\JobAnalysisRequirementStrength;
use App\Exceptions\ImmutableJobAnalysisSnapshotException;
use App\Exceptions\InvalidJobAnalysisExperienceRangeException;
use Database\Factories\JobAnalysisFindingFactory;
use Illuminate\Database\Eloquent\Attributes\Boot;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A single discrete observation extracted from the owning JobAnalysis's
 * JobPosting — e.g. "5+ years of backend experience", "travel up to 25%",
 * "ERP experience is not required". Belongs to exactly one JobAnalysis
 * snapshot and is immutable for the same reason: a correction means a new
 * JobAnalysis, never an edit to an existing Finding.
 *
 * `requirement_strength` is nullable and has only three real values
 * (required / preferred / not_required). Null does not mean "unknown" —
 * it means the category doesn't carry a requirement strength at all
 * (e.g. a culture_signal or success_measure), or the posting is silent
 * on it. Distinguish "not_required" (the posting explicitly disclaims
 * it, e.g. Rootstock's ERP disclaimer) from null/silence (the posting
 * simply never brings it up) — collapsing these loses real signal. See
 * docs/domain-model.md "JobAnalysis".
 *
 * `years_experience_min`/`years_experience_max` are a faithful
 * transcription of a stated number or range, never a computed
 * eligibility floor/ceiling. "5+ years" is min=5.0, max=null. "7-10
 * years" is min=7.0, max=10.0.
 *
 * Deliberately holds no relationship to CareerFact, Skill, Project,
 * Employer, or Role — see docs/domain-model.md's candidate-independence
 * boundary.
 *
 * @property int $id
 * @property int $job_analysis_id
 * @property JobAnalysisFindingCategory $category
 * @property string $statement
 * @property string|null $label
 * @property JobAnalysisFindingBasis $basis
 * @property JobAnalysisRequirementStrength|null $requirement_strength
 * @property JobAnalysisEmphasis|null $emphasis
 * @property JobAnalysisMaturity|null $maturity
 * @property float|null $years_experience_min
 * @property float|null $years_experience_max
 * @property string|null $recency_requirement
 * @property string|null $time_horizon
 * @property string|null $notes
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'job_analysis_id',
    'category',
    'statement',
    'label',
    'basis',
    'requirement_strength',
    'emphasis',
    'maturity',
    'years_experience_min',
    'years_experience_max',
    'recency_requirement',
    'time_horizon',
    'notes',
])]
class JobAnalysisFinding extends Model
{
    /** @use HasFactory<JobAnalysisFindingFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'category' => JobAnalysisFindingCategory::class,
            'basis' => JobAnalysisFindingBasis::class,
            'requirement_strength' => JobAnalysisRequirementStrength::class,
            'emphasis' => JobAnalysisEmphasis::class,
            'maturity' => JobAnalysisMaturity::class,
            'years_experience_min' => 'float',
            'years_experience_max' => 'float',
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
     * @return HasMany<JobAnalysisFindingEvidence, $this>
     */
    public function evidence(): HasMany
    {
        return $this->hasMany(JobAnalysisFindingEvidence::class);
    }

    /**
     * Mirrors JobAnalysis::preventUpdates() — a Finding belonging to an
     * already-created snapshot is never edited in place. Only fires on
     * updates to an already-persisted row, not on initial creation, so
     * building a snapshot's Findings (and their Evidence) is unaffected.
     */
    #[Boot]
    protected static function preventUpdates(): void
    {
        static::updating(function (self $finding) {
            throw new ImmutableJobAnalysisSnapshotException(
                'JobAnalysisFinding rows are immutable once created — create a new JobAnalysis snapshot instead of updating an existing finding.'
            );
        });
    }

    /**
     * Guards years_experience_min/_max on every save (create and update):
     * neither may be negative, and max may not be less than min when both
     * are present. This runs on `saving`, not `updating`, because the
     * invariant must also hold at creation time — it isn't about
     * immutability.
     */
    #[Boot]
    protected static function enforceValidExperienceRange(): void
    {
        static::saving(function (self $finding) {
            $finding->assertValidExperienceRange();
        });
    }

    protected function assertValidExperienceRange(): void
    {
        $min = $this->years_experience_min;
        $max = $this->years_experience_max;

        if ($min !== null && $min < 0) {
            throw new InvalidJobAnalysisExperienceRangeException(
                'years_experience_min cannot be negative.'
            );
        }

        if ($max !== null && $max < 0) {
            throw new InvalidJobAnalysisExperienceRangeException(
                'years_experience_max cannot be negative.'
            );
        }

        if ($min !== null && $max !== null && $max < $min) {
            throw new InvalidJobAnalysisExperienceRangeException(
                'years_experience_max cannot be less than years_experience_min.'
            );
        }
    }
}
