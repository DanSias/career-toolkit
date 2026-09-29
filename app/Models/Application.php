<?php

namespace App\Models;

use App\Enums\ApplicationStatus;
use Database\Factories\ApplicationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A candidate's mutable operational attempt to evaluate or pursue one
 * JobPosting — the first entity in this domain that is neither
 * canonical data nor an immutable AI-generated snapshot. See
 * docs/domain-model.md "Application" and docs/application-inspector.md.
 *
 * status is real, mutable state (App\Enums\ApplicationStatus) — not a
 * snapshot version like JobAnalysis/JobMatch/ResumeVariant, because it
 * describes an ongoing real-world process rather than a point-in-time
 * generation result.
 *
 * @property int $id
 * @property int $job_posting_id
 * @property ApplicationStatus $status
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['job_posting_id', 'status'])]
class Application extends Model
{
    /** @use HasFactory<ApplicationFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'status' => ApplicationStatus::class,
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
     * @return HasMany<WorkflowRun, $this>
     */
    public function workflowRuns(): HasMany
    {
        return $this->hasMany(WorkflowRun::class);
    }

    /**
     * Every field/question discovered on this Application across all of
     * its AgentRuns.
     *
     * @return HasMany<ApplicationQuestion, $this>
     */
    public function applicationQuestions(): HasMany
    {
        return $this->hasMany(ApplicationQuestion::class);
    }
}
