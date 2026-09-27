<?php

use App\Enums\GenerationStatus;
use App\Enums\GenerationType;
use App\Models\GenerationAttempt;
use App\Models\JobAnalysis;
use App\Models\JobMatch;
use App\Models\JobPosting;
use App\Models\ResumeVariant;
use Illuminate\Support\Facades\Schema;

/**
 * Foundation-only coverage: GenerationAttempt is a small persistence
 * model with no orchestration behavior yet (nothing queues, dispatches,
 * or transitions one) — see docs/job-analysis-generation.md "Durable
 * generation attempts (foundation)". These tests prove the schema,
 * enums, subject/result resolution, and the no-raw-content contract,
 * not any generation pipeline behavior.
 */
it('keeps GenerationType\'s backed values stable', function () {
    expect(GenerationType::JobAnalysis->value)->toBe('job_analysis')
        ->and(GenerationType::JobMatch->value)->toBe('job_match')
        ->and(GenerationType::ResumeVariant->value)->toBe('resume_variant');
});

it('keeps GenerationStatus\'s backed values stable', function () {
    expect(GenerationStatus::Queued->value)->toBe('queued')
        ->and(GenerationStatus::Running->value)->toBe('running')
        ->and(GenerationStatus::Succeeded->value)->toBe('succeeded')
        ->and(GenerationStatus::Failed->value)->toBe('failed');
});

it('reports Queued and Running as the active statuses, in that order', function () {
    expect(GenerationStatus::active())->toBe([GenerationStatus::Queued, GenerationStatus::Running]);
});

it('persists an attempt at each lifecycle status', function () {
    foreach (GenerationStatus::cases() as $status) {
        $attempt = GenerationAttempt::factory()->create(['status' => $status]);

        expect($attempt->fresh()->status)->toBe($status);
    }
});

it('resolves the subject morph to a JobPosting for a job_analysis attempt', function () {
    $jobPosting = JobPosting::factory()->create();
    $attempt = GenerationAttempt::factory()->create([
        'generation_type' => GenerationType::JobAnalysis,
        'subject_type' => (new JobPosting)->getMorphClass(),
        'subject_id' => $jobPosting->id,
    ]);

    expect($attempt->subject)->toBeInstanceOf(JobPosting::class)
        ->and($attempt->subject->id)->toBe($jobPosting->id);
});

it('resolves the subject morph to a JobAnalysis for a job_match attempt', function () {
    $jobAnalysis = JobAnalysis::factory()->create();
    $attempt = GenerationAttempt::factory()->create([
        'generation_type' => GenerationType::JobMatch,
        'subject_type' => (new JobAnalysis)->getMorphClass(),
        'subject_id' => $jobAnalysis->id,
    ]);

    expect($attempt->subject)->toBeInstanceOf(JobAnalysis::class)
        ->and($attempt->subject->id)->toBe($jobAnalysis->id);
});

it('resolves the subject morph to a JobMatch for a resume_variant attempt', function () {
    $jobMatch = JobMatch::factory()->create();
    $attempt = GenerationAttempt::factory()->create([
        'generation_type' => GenerationType::ResumeVariant,
        'subject_type' => (new JobMatch)->getMorphClass(),
        'subject_id' => $jobMatch->id,
    ]);

    expect($attempt->subject)->toBeInstanceOf(JobMatch::class)
        ->and($attempt->subject->id)->toBe($jobMatch->id);
});

it('resolves a succeeded job_analysis attempt\'s result to the JobAnalysis it produced', function () {
    $jobAnalysis = JobAnalysis::factory()->create();
    $attempt = GenerationAttempt::factory()->create([
        'generation_type' => GenerationType::JobAnalysis,
        'status' => GenerationStatus::Succeeded,
        'result_id' => $jobAnalysis->id,
    ]);

    expect($attempt->result())->toBeInstanceOf(JobAnalysis::class)
        ->and($attempt->result()->id)->toBe($jobAnalysis->id);
});

it('resolves a succeeded job_match attempt\'s result to the JobMatch it produced', function () {
    $jobMatch = JobMatch::factory()->create();
    $attempt = GenerationAttempt::factory()->create([
        'generation_type' => GenerationType::JobMatch,
        'status' => GenerationStatus::Succeeded,
        'result_id' => $jobMatch->id,
    ]);

    expect($attempt->result())->toBeInstanceOf(JobMatch::class)
        ->and($attempt->result()->id)->toBe($jobMatch->id);
});

it('resolves a succeeded resume_variant attempt\'s result to the ResumeVariant it produced', function () {
    $resumeVariant = ResumeVariant::factory()->create();
    $attempt = GenerationAttempt::factory()->create([
        'generation_type' => GenerationType::ResumeVariant,
        'status' => GenerationStatus::Succeeded,
        'result_id' => $resumeVariant->id,
    ]);

    expect($attempt->result())->toBeInstanceOf(ResumeVariant::class)
        ->and($attempt->result()->id)->toBe($resumeVariant->id);
});

it('returns null for result() when result_id is not yet set', function () {
    $attempt = GenerationAttempt::factory()->create(['status' => GenerationStatus::Running]);

    expect($attempt->result())->toBeNull();
});

it('persists nullable provider/version/token diagnostics correctly', function () {
    $attempt = GenerationAttempt::factory()->create([
        'status' => GenerationStatus::Failed,
        'provider' => 'ollama',
        'model' => 'qwen3.8:27b',
        'prompt_version' => 'job-analysis-v3',
        'schema_version' => '1.0',
        'prompt_tokens' => 5162,
        'completion_tokens' => 14774,
        'total_tokens' => 19936,
        'finish_reason' => 'stop',
        'failure_category' => 'evidence_verification',
        'failure_message' => 'Evidence excerpt for findings.22.evidence.1 could not be verified.',
    ]);

    $fresh = $attempt->fresh();

    expect($fresh->provider)->toBe('ollama')
        ->and($fresh->model)->toBe('qwen3.8:27b')
        ->and($fresh->prompt_version)->toBe('job-analysis-v3')
        ->and($fresh->schema_version)->toBe('1.0')
        ->and($fresh->prompt_tokens)->toBe(5162)
        ->and($fresh->completion_tokens)->toBe(14774)
        ->and($fresh->total_tokens)->toBe(19936)
        ->and($fresh->finish_reason)->toBe('stop')
        ->and($fresh->failure_category)->toBe('evidence_verification')
        ->and($fresh->failure_message)->toBe('Evidence excerpt for findings.22.evidence.1 could not be verified.');
});

it('leaves every diagnostic/version/failure/result column null when never set', function () {
    $attempt = GenerationAttempt::factory()->create();

    expect($attempt->provider)->toBeNull()
        ->and($attempt->model)->toBeNull()
        ->and($attempt->prompt_version)->toBeNull()
        ->and($attempt->schema_version)->toBeNull()
        ->and($attempt->prompt_tokens)->toBeNull()
        ->and($attempt->completion_tokens)->toBeNull()
        ->and($attempt->total_tokens)->toBeNull()
        ->and($attempt->finish_reason)->toBeNull()
        ->and($attempt->failure_category)->toBeNull()
        ->and($attempt->failure_message)->toBeNull()
        ->and($attempt->started_at)->toBeNull()
        ->and($attempt->finished_at)->toBeNull()
        ->and($attempt->result_id)->toBeNull();
});

it('persists queued_at, started_at, and finished_at independently through the lifecycle', function () {
    $queuedAt = now()->subMinutes(10);
    $startedAt = now()->subMinutes(9);
    $finishedAt = now();

    $attempt = GenerationAttempt::factory()->create([
        'status' => GenerationStatus::Succeeded,
        'queued_at' => $queuedAt,
        'started_at' => $startedAt,
        'finished_at' => $finishedAt,
    ]);

    $fresh = $attempt->fresh();

    // Compared at whole-second precision — the `timestamp` column type
    // doesn't retain microseconds, so an exact Carbon equalTo() would
    // fail on precision alone, not on the value actually persisted.
    expect($fresh->queued_at->toDateTimeString())->toBe($queuedAt->toDateTimeString())
        ->and($fresh->started_at->toDateTimeString())->toBe($startedAt->toDateTimeString())
        ->and($fresh->finished_at->toDateTimeString())->toBe($finishedAt->toDateTimeString());
});

it('scopeActive returns only queued and running attempts, excluding succeeded and failed', function () {
    $queued = GenerationAttempt::factory()->create(['status' => GenerationStatus::Queued]);
    $running = GenerationAttempt::factory()->create(['status' => GenerationStatus::Running]);
    GenerationAttempt::factory()->create(['status' => GenerationStatus::Succeeded]);
    GenerationAttempt::factory()->create(['status' => GenerationStatus::Failed]);

    $active = GenerationAttempt::query()->active()->pluck('id')->sort()->values();

    expect($active->all())->toBe(collect([$queued->id, $running->id])->sort()->values()->all());
});

it('finds the active attempt for a specific subject and generation_type, ignoring other subjects/types', function () {
    $jobPosting = JobPosting::factory()->create();
    $otherJobPosting = JobPosting::factory()->create();

    $target = GenerationAttempt::factory()->create([
        'generation_type' => GenerationType::JobAnalysis,
        'subject_type' => (new JobPosting)->getMorphClass(),
        'subject_id' => $jobPosting->id,
        'status' => GenerationStatus::Running,
    ]);

    // Different subject entirely.
    GenerationAttempt::factory()->create([
        'generation_type' => GenerationType::JobAnalysis,
        'subject_type' => (new JobPosting)->getMorphClass(),
        'subject_id' => $otherJobPosting->id,
        'status' => GenerationStatus::Running,
    ]);

    // Same subject, but already finished — must not count as active.
    GenerationAttempt::factory()->create([
        'generation_type' => GenerationType::JobAnalysis,
        'subject_type' => (new JobPosting)->getMorphClass(),
        'subject_id' => $jobPosting->id,
        'status' => GenerationStatus::Succeeded,
    ]);

    $found = GenerationAttempt::query()
        ->active()
        ->where('subject_type', (new JobPosting)->getMorphClass())
        ->where('subject_id', $jobPosting->id)
        ->where('generation_type', GenerationType::JobAnalysis)
        ->get();

    expect($found)->toHaveCount(1)
        ->and($found->first()->id)->toBe($target->id);
});

it('does not persist any raw prompt/response/candidate content column', function () {
    $forbiddenColumns = [
        'prompt', 'system_prompt', 'user_prompt', 'raw_response', 'raw_content',
        'description', 'job_description', 'candidate_payload', 'input_snapshot',
        'evidence', 'excerpt', 'excerpts',
    ];

    $columns = Schema::getColumnListing('generation_attempts');

    foreach ($forbiddenColumns as $forbidden) {
        expect($columns)->not->toContain($forbidden);
    }
});

it('keeps GenerationAttempt\'s fillable list limited to lifecycle/version/diagnostic fields only', function () {
    $attempt = new GenerationAttempt;

    expect($attempt->getFillable())->toBe([
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
    ]);
});

it('indexes (subject_type, subject_id, generation_type, status) for the active-attempt lookup', function () {
    $indexes = Schema::getIndexes('generation_attempts');
    $columns = collect($indexes)->firstWhere('name', 'generation_attempts_active_lookup_index')['columns'] ?? null;

    expect($columns)->toBe(['subject_type', 'subject_id', 'generation_type', 'status']);
});
