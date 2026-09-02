<?php

namespace App\Models;

use App\Exceptions\ImmutableJobAnalysisSnapshotException;
use Database\Factories\JobAnalysisFindingEvidenceFactory;
use Illuminate\Database\Eloquent\Attributes\Boot;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A verbatim excerpt from the owning JobPosting's description that
 * substantiates one JobAnalysisFinding. A Finding may have several of
 * these (e.g. a requirement stated twice in different sections of the
 * posting) — see docs/domain-model.md "JobAnalysis" for why this is a
 * real hasMany table rather than an inline column or JSON array.
 *
 * Cannot exist without its parent JobAnalysisFinding (enforced by the
 * foreign key and cascading delete), and is immutable for the same
 * "snapshot" reason as JobAnalysis and JobAnalysisFinding.
 *
 * @property int $id
 * @property int $job_analysis_finding_id
 * @property string $excerpt
 * @property string|null $source_section
 * @property string|null $source_locator
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Table('job_analysis_finding_evidence')]
#[Fillable([
    'job_analysis_finding_id',
    'excerpt',
    'source_section',
    'source_locator',
])]
class JobAnalysisFindingEvidence extends Model
{
    /** @use HasFactory<JobAnalysisFindingEvidenceFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<JobAnalysisFinding, $this>
     */
    public function jobAnalysisFinding(): BelongsTo
    {
        return $this->belongsTo(JobAnalysisFinding::class);
    }

    /**
     * Mirrors JobAnalysis::preventUpdates() and
     * JobAnalysisFinding::preventUpdates() — see those for the reasoning.
     * Only fires on updates to an already-persisted row, so creating
     * Evidence rows under a freshly-created Finding is unaffected.
     */
    #[Boot]
    protected static function preventUpdates(): void
    {
        static::updating(function (self $evidence) {
            throw new ImmutableJobAnalysisSnapshotException(
                'JobAnalysisFindingEvidence rows are immutable once created — create a new JobAnalysis snapshot instead of updating existing evidence.'
            );
        });
    }
}
