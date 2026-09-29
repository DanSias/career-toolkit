<?php

namespace App\Models;

use App\Enums\WorkflowStatus;
use Database\Factories\WorkflowStepFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One bounded step within a WorkflowRun — "perform this bounded step in
 * that workflow." Represents INTENT; an AgentRun represents one
 * concrete execution attempt at satisfying it. See
 * docs/domain-model.md "WorkflowRun, WorkflowStep, and AgentRun".
 *
 * step_key is a plain string, not a backed enum — exactly one real
 * value ('inspect_application') exists today, and a shared
 * cross-workflow-type enum would mix unrelated vocabularies before a
 * second workflow type justifies the abstraction.
 *
 * @property int $id
 * @property int $workflow_run_id
 * @property string $step_key
 * @property WorkflowStatus $status
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 * @property string|null $failure_message
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'workflow_run_id',
    'step_key',
    'status',
    'started_at',
    'finished_at',
    'failure_message',
])]
class WorkflowStep extends Model
{
    /** @use HasFactory<WorkflowStepFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'status' => WorkflowStatus::class,
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<WorkflowRun, $this>
     */
    public function workflowRun(): BelongsTo
    {
        return $this->belongsTo(WorkflowRun::class);
    }

    /**
     * Every execution attempt made against this step. May legitimately
     * hold more than one row (a retry is always a new AgentRun, never a
     * reopened one — see App\Models\AgentRun), though no orchestration
     * in this codebase creates a second AgentRun under an
     * already-existing WorkflowStep as of this class.
     *
     * @return HasMany<AgentRun, $this>
     */
    public function agentRuns(): HasMany
    {
        return $this->hasMany(AgentRun::class);
    }
}
