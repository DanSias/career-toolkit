<?php

namespace App\Models;

use App\Enums\AgentRunFailureCategory;
use App\Enums\AgentRunStatus;
use App\Enums\InspectionOutcome;
use Database\Factories\AgentRunFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One execution attempt by an external worker satisfying a
 * WorkflowStep — deliberately "one execution attempt by an external
 * worker," not "a generic autonomous AI agent." For the Application
 * Inspector, the worker is a narrowly-scoped browser extraction
 * process, not an agent that reasons about what to do next. See
 * docs/domain-model.md "WorkflowRun, WorkflowStep, and AgentRun" and
 * docs/application-inspector.md.
 *
 * status and inspection_outcome are deliberately orthogonal: status
 * answers only "did the browser execution itself complete without
 * error"; inspection_outcome (populated only when status is Succeeded)
 * separately answers "what was actually achieved." A technically
 * successful execution that correctly stopped at an authentication
 * wall is status: Succeeded, inspection_outcome:
 * AuthenticationRequired, never a failure.
 *
 * agent_type is a plain string, not a backed enum — exactly one real
 * value ('browser_inspector') exists today.
 *
 * @property int $id
 * @property int $workflow_step_id
 * @property string $agent_type
 * @property AgentRunStatus $status
 * @property string|null $worker_identity
 * @property Carbon|null $started_at
 * @property Carbon|null $claim_expires_at
 * @property Carbon|null $finished_at
 * @property InspectionOutcome|null $inspection_outcome
 * @property string|null $inspection_stop_reason
 * @property string|null $ats_detected
 * @property AgentRunFailureCategory|null $failure_category
 * @property string|null $failure_message
 * @property array<string, mixed>|null $result_summary
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'workflow_step_id',
    'agent_type',
    'status',
    'worker_identity',
    'started_at',
    'claim_expires_at',
    'finished_at',
    'inspection_outcome',
    'inspection_stop_reason',
    'ats_detected',
    'failure_category',
    'failure_message',
    'result_summary',
])]
class AgentRun extends Model
{
    /** @use HasFactory<AgentRunFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'status' => AgentRunStatus::class,
            'started_at' => 'datetime',
            'claim_expires_at' => 'datetime',
            'finished_at' => 'datetime',
            'inspection_outcome' => InspectionOutcome::class,
            'failure_category' => AgentRunFailureCategory::class,
            'result_summary' => 'array',
        ];
    }

    /**
     * @return BelongsTo<WorkflowStep, $this>
     */
    public function workflowStep(): BelongsTo
    {
        return $this->belongsTo(WorkflowStep::class);
    }

    /**
     * @return HasMany<ApplicationQuestion, $this>
     */
    public function applicationQuestions(): HasMany
    {
        return $this->hasMany(ApplicationQuestion::class);
    }

    /**
     * Queued or Running — an attempt that is not yet finished. Matches
     * the (workflow_step_id, status) index on the agent_runs table.
     *
     * @param  Builder<AgentRun>  $query
     * @return Builder<AgentRun>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereIn('status', AgentRunStatus::active());
    }
}
