<?php

namespace App\Models;

use App\Enums\MatchRelationship;
use App\Exceptions\ImmutableJobMatchSnapshotException;
use Database\Factories\CareerFactMatchFactory;
use Illuminate\Database\Eloquent\Attributes\Boot;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One CareerFact judged relevant to one JobMatchFinding, and what KIND
 * of support it provides (`relationship`) — categorical, not an
 * ordinal quality score. See docs/job-match-contract.md.
 *
 * The CareerFact reference IS the evidence — there is no separate
 * quote/excerpt system here the way JobAnalysisFindingEvidence exists
 * for JobAnalysis. `rationale` is model-generated explanatory
 * commentary about WHY this fact is relevant, not itself evidence, and
 * is never safe to copy into any future employer-facing artifact. See
 * docs/domain-model.md "JobMatch" (`coverage_rationale`/`rationale`
 * are internal model-generated commentary only) and
 * docs/job-match-contract.md "Field notes".
 *
 * Deliberately does NOT cascade-delete when its CareerFact is deleted
 * (`career_fact_id` is a restricted foreign key) — this row is part of
 * an immutable historical result, not a cache of live data. See the
 * career_fact_matches migration and docs/job-match-generation.md.
 *
 * @property int $id
 * @property int $job_match_finding_id
 * @property int $career_fact_id
 * @property MatchRelationship $relationship
 * @property string|null $rationale
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'job_match_finding_id',
    'career_fact_id',
    'relationship',
    'rationale',
])]
class CareerFactMatch extends Model
{
    /** @use HasFactory<CareerFactMatchFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'relationship' => MatchRelationship::class,
        ];
    }

    /**
     * @return BelongsTo<JobMatchFinding, $this>
     */
    public function jobMatchFinding(): BelongsTo
    {
        return $this->belongsTo(JobMatchFinding::class);
    }

    /**
     * The live CareerFact this match cites. Always resolve
     * `careerFact->visibility` from this relation at read time for any
     * output-facing use — never assume a match's eligibility from the
     * time it was generated. See docs/job-match-contract.md
     * "Visibility resolves live, never frozen".
     *
     * @return BelongsTo<CareerFact, $this>
     */
    public function careerFact(): BelongsTo
    {
        return $this->belongsTo(CareerFact::class);
    }

    /**
     * Mirrors JobAnalysisFindingEvidence::preventUpdates(). Only fires
     * on updates to an already-persisted row, so creating a fresh
     * snapshot's match rows is unaffected.
     */
    #[Boot]
    protected static function preventUpdates(): void
    {
        static::updating(function (self $match) {
            throw new ImmutableJobMatchSnapshotException(
                'CareerFactMatch rows are immutable once created — create a new JobMatch snapshot instead of updating an existing match.'
            );
        });
    }
}
