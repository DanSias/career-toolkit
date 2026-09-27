<?php

namespace App\Models;

use App\Enums\GenerationStatus;
use App\Enums\GenerationType;
use Database\Factories\GenerationAttemptFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * Durable operational metadata/history for one attempt at a generation
 * stage (Job Analysis, Job Match, or Resume generation) — queued,
 * running, succeeded, or failed, with enough version/diagnostic
 * metadata and lifecycle timestamps to reconstruct what happened and
 * how long it took, without a browser tab needing to stay open or a
 * client-side timer needing to be trusted as the source of truth.
 *
 * Job Analysis and Job Match generation both create, queue, and
 * transition attempts through this model today (see
 * docs/job-analysis-generation.md "Durable generation attempts
 * (foundation)" and docs/job-match-generation.md "Async Job Match");
 * Resume generation remains synchronous and does not yet use it. It
 * exists as its own small persistence model — it does not call a
 * provider, run a prompt, or validate a response; that remains
 * entirely GenerateJobAnalysis/GenerateJobMatch/GenerateResumeVariant's
 * job, reused unchanged.
 *
 * Deliberately stores NO raw generation content — no prompts, provider
 * responses, job description text, candidate/profile data, evidence
 * excerpts, or input snapshots. Every column here is either lifecycle
 * state, a version identifier, or the same non-content diagnostic
 * shape already proven safe by App\Support\ProviderDiagnostics::
 * toLogContext() (model, finish_reason, token counts, provider/
 * timeout config). A successful attempt's actual generated content
 * lives where it always has — on the resulting JobAnalysis/JobMatch/
 * ResumeVariant row itself (`raw_response`/`input_snapshot`) — this
 * table only points at it via result_id.
 *
 * subject (subject_type/subject_id) is a genuine polymorphic morphTo,
 * matching this codebase's existing CareerFact::attributable()
 * convention (see AppServiceProvider::configureMorphMap(), which this
 * model's aliases are also registered in) — real value here since a
 * GenerationAttempt's subject is one of three structurally unrelated
 * models depending on generation_type, and a subject-side inverse
 * relation (e.g. JobPosting::generationAttempts()) is a natural future
 * addition. result is deliberately NOT a second morphTo: generation_type
 * already determines the result's model class 1:1 (JobAnalysis for
 * job_analysis, JobMatch for job_match, ResumeVariant for
 * resume_variant), so a separate result_type column would only ever
 * duplicate generation_type — two columns a future migration could let
 * drift out of sync for no real benefit, since (unlike subject) nothing
 * in this app needs to query "which attempts produced this JobAnalysis"
 * in the reverse direction yet. See result() below.
 *
 * @property int $id
 * @property string $subject_type
 * @property int $subject_id
 * @property GenerationType $generation_type
 * @property GenerationStatus $status
 * @property Carbon|null $queued_at
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 * @property string|null $provider
 * @property string|null $model
 * @property string|null $prompt_version
 * @property string|null $schema_version
 * @property int|null $prompt_tokens
 * @property int|null $completion_tokens
 * @property int|null $total_tokens
 * @property string|null $finish_reason
 * @property string|null $failure_category
 * @property string|null $failure_message
 * @property int|null $result_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'subject_type',
    'subject_id',
    'generation_type',
    'status',
    'queued_at',
    'started_at',
    'finished_at',
    'provider',
    'model',
    'prompt_version',
    'schema_version',
    'prompt_tokens',
    'completion_tokens',
    'total_tokens',
    'finish_reason',
    'failure_category',
    'failure_message',
    'result_id',
])]
class GenerationAttempt extends Model
{
    /** @use HasFactory<GenerationAttemptFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'generation_type' => GenerationType::class,
            'status' => GenerationStatus::class,
            'queued_at' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /**
     * The JobPosting, JobAnalysis, or JobMatch this attempt runs
     * against — see GenerationType's docblock for exactly which one,
     * per generation_type.
     *
     * @return MorphTo<Model, $this>
     */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * The JobAnalysis, JobMatch, or ResumeVariant this attempt produced
     * — null until (and unless) the attempt succeeds. Not an Eloquent
     * relation: generation_type already says which model class
     * result_id refers to, so this is a plain lookup rather than a
     * second polymorphic type column. See this class's docblock for
     * why.
     */
    public function result(): JobAnalysis|JobMatch|ResumeVariant|null
    {
        if ($this->result_id === null) {
            return null;
        }

        return match ($this->generation_type) {
            GenerationType::JobAnalysis => JobAnalysis::find($this->result_id),
            GenerationType::JobMatch => JobMatch::find($this->result_id),
            GenerationType::ResumeVariant => ResumeVariant::find($this->result_id),
        };
    }

    /**
     * Queued or Running attempts only — the query the upcoming
     * dispatch step will use to check whether a subject+stage already
     * has work in flight before starting another. Matches the
     * (subject_type, subject_id, generation_type, status) index on the
     * generation_attempts table.
     *
     * @param  Builder<GenerationAttempt>  $query
     * @return Builder<GenerationAttempt>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereIn('status', GenerationStatus::active());
    }
}
