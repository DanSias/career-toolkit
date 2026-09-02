<?php

namespace App\Models;

use App\Enums\MatchRelationship;
use App\Exceptions\ImmutableJobMatchSnapshotException;
use Database\Factories\EducationMatchFactory;
use Illuminate\Database\Eloquent\Attributes\Boot;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One Education record judged relevant to one JobMatchFinding — e.g. a
 * degree satisfying a stated education requirement. Same shape and same
 * `relationship` vocabulary as CareerFactMatch, kept as a separate,
 * small, concrete table rather than a generic polymorphic "candidate
 * support" relation: Education is genuinely a different kind of data
 * from CareerFact (no verification, no visibility, no Evidence of its
 * own — see Education's own docblock), so a shared abstraction would
 * either force artificial symmetry or leak type-specific branching into
 * generic code anyway. See docs/job-match-contract.md.
 *
 * Education carries no `visibility` field at all, unlike CareerFact —
 * that does NOT mean an EducationMatch is automatically safe/eligible
 * for a future employer-facing artifact. It means CareerFact's specific
 * confidentiality gate simply doesn't apply here; a future
 * output-facing stage still separately decides whether a given
 * Education record belongs in a given output. See
 * docs/domain-model.md "JobMatch" ("Education has no `visibility`
 * field, and that absence is not a grant of default output
 * eligibility").
 *
 * Deliberately does NOT cascade-delete when its Education row is
 * deleted (`education_id` is a restricted foreign key) — same reasoning
 * as CareerFactMatch. See the education_matches migration.
 *
 * @property int $id
 * @property int $job_match_finding_id
 * @property int $education_id
 * @property MatchRelationship $relationship
 * @property string|null $rationale
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'job_match_finding_id',
    'education_id',
    'relationship',
    'rationale',
])]
class EducationMatch extends Model
{
    /** @use HasFactory<EducationMatchFactory> */
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
     * @return BelongsTo<Education, $this>
     */
    public function education(): BelongsTo
    {
        return $this->belongsTo(Education::class);
    }

    /**
     * Mirrors CareerFactMatch::preventUpdates().
     */
    #[Boot]
    protected static function preventUpdates(): void
    {
        static::updating(function (self $match) {
            throw new ImmutableJobMatchSnapshotException(
                'EducationMatch rows are immutable once created — create a new JobMatch snapshot instead of updating an existing match.'
            );
        });
    }
}
