<?php

namespace App\Models;

use App\Enums\WorkflowStatus;
use App\Enums\WorkflowType;
use Database\Factories\WorkflowRunFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One durable execution of a business process against an Application —
 * "accomplish this durable business process." Sits above
 * App\Models\GenerationAttempt, not a replacement or generalization of
 * it. See docs/domain-model.md "WorkflowRun, WorkflowStep, and
 * AgentRun" and docs/application-inspector.md.
 *
 * application_id is a plain, required FK, deliberately not a
 * polymorphic subject — see this class's own docblock precedent in
 * GenerationAttempt::subject() for why that would be premature here:
 * there is exactly one real WorkflowRun subject type today.
 *
 * @property int $id
 * @property int $application_id
 * @property WorkflowType $workflow_type
 * @property WorkflowStatus $status
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 * @property string|null $failure_message
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'application_id',
    'workflow_type',
    'status',
    'started_at',
    'finished_at',
    'failure_message',
])]
class WorkflowRun extends Model
{
    /** @use HasFactory<WorkflowRunFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'workflow_type' => WorkflowType::class,
            'status' => WorkflowStatus::class,
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Application, $this>
     */
    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    /**
     * @return HasMany<WorkflowStep, $this>
     */
    public function workflowSteps(): HasMany
    {
        return $this->hasMany(WorkflowStep::class);
    }

    /**
     * Pending or Running — the query "does this Application already
     * have work in flight" uses. Matches the (application_id, status)
     * index on the workflow_runs table.
     *
     * @param  Builder<WorkflowRun>  $query
     * @return Builder<WorkflowRun>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereIn('status', WorkflowStatus::active());
    }
}
