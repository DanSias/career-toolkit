<?php

namespace App\Jobs;

use App\Enums\GenerationStatus;
use App\Exceptions\InvalidJobAnalysisResponseException;
use App\Exceptions\JobAnalysisProviderException;
use App\Models\GenerationAttempt;
use App\Models\JobPosting;
use App\Support\JobAnalysis\GenerateJobAnalysis;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * A thin orchestration wrapper around the existing, unmodified
 * GenerateJobAnalysis service — this class owns only the
 * GenerationAttempt lifecycle (queued -> running -> succeeded/failed),
 * never prompt/provider/validation logic. See
 * docs/job-analysis-generation.md "Durable generation attempts
 * (foundation)" and "Async Job Analysis" below it.
 *
 * $tries = 1 deliberately: OllamaChatCompletionsClient already retries
 * transient transport failures internally (3 attempts). An outer
 * Laravel queue retry on top of that would silently multiply real
 * provider calls for a single logical attempt — exactly what the
 * queue's retry_after margin (see config/queue.php) is sized to avoid
 * happening via a *different* mechanism (a second worker), not
 * something this job should also risk via its own retry count.
 *
 * Only two exception types are caught inside handle() and translated
 * into a `failed` GenerationAttempt without rethrowing:
 * JobAnalysisProviderException and InvalidJobAnalysisResponseException
 * — the same two JobAnalysisController::store() already treats as
 * ordinary, expected generation-failure outcomes today (see its own
 * catch block). Catching them here and returning normally (rather than
 * rethrowing) is not swallowing an error to hide it — it mirrors the
 * synchronous controller's own behavior exactly: a well-diagnosed
 * generation failure is not a queue/infrastructure failure, so the job
 * completes normally with the attempt durably marked failed. Anything
 * else (a genuinely unexpected exception) is deliberately left
 * uncaught here, so it propagates to Laravel's queue machinery (visible
 * in `failed_jobs`, not silently absorbed) — failed() below is what
 * guarantees the attempt still can't be left stuck in `running` when
 * that happens.
 */
final class GenerateJobAnalysisJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(
        public readonly GenerationAttempt $attempt,
    ) {}

    public function handle(GenerateJobAnalysis $generator): void
    {
        $this->attempt->update([
            'status' => GenerationStatus::Running,
            'started_at' => now(),
        ]);

        $posting = $this->attempt->subject;

        if (! $posting instanceof JobPosting) {
            $this->markFailed('unexpected_error', 'GenerationAttempt subject was not a JobPosting.');

            return;
        }

        try {
            $analysis = $generator->generate($posting);
        } catch (JobAnalysisProviderException $e) {
            $diagnostics = $e->diagnostics;

            Log::warning('JobAnalysis generation failed.', [
                'job_posting_id' => $posting->id,
                'generation_attempt_id' => $this->attempt->id,
                'exception' => $e::class,
                'message' => $e->getMessage(),
                ...($diagnostics?->toLogContext() ?? []),
            ]);

            $this->markFailed('provider_error', $e->getMessage(), [
                'model' => $diagnostics?->model,
                'finish_reason' => $diagnostics?->finishReason,
                'prompt_tokens' => $diagnostics?->usage['prompt_tokens'] ?? null,
                'completion_tokens' => $diagnostics?->usage['completion_tokens'] ?? null,
                'total_tokens' => $diagnostics?->usage['total_tokens'] ?? null,
            ]);

            return;
        } catch (InvalidJobAnalysisResponseException $e) {
            // $e->context (when present) carries the actual offending
            // excerpt AND the finding it belongs to (every field,
            // including its other evidence entries) for
            // evidence-verification failures. The finding is never
            // logged or persisted here — see
            // logEvidenceVerificationDiagnostic() below, which reads
            // only the three approved keys out of $context by name,
            // never the array wholesale.
            $category = $e->context !== null ? 'evidence_verification_error' : 'validation_error';

            Log::warning('JobAnalysis generation failed.', [
                'job_posting_id' => $posting->id,
                'generation_attempt_id' => $this->attempt->id,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            if ($category === 'evidence_verification_error') {
                $this->logEvidenceVerificationDiagnostic($posting, $e);
            }

            $this->markFailed($category, $e->getMessage());

            return;
        }

        [$provider, $model] = $this->splitGeneratedBy($analysis->generated_by);

        $this->attempt->update([
            'status' => GenerationStatus::Succeeded,
            'finished_at' => now(),
            'result_id' => $analysis->id,
            'provider' => $provider,
            'model' => $model,
            'prompt_version' => $analysis->prompt_version,
            'schema_version' => $analysis->schema_version,
            // Token/finish_reason diagnostics are only ever captured on
            // a *failed* provider call today (see
            // OllamaJobAnalysisClient) — GenerateJobAnalysis's success
            // path doesn't expose them, and widening that contract is
            // out of scope for this milestone. Left null here rather
            // than guessed at.
        ]);
    }

    /**
     * Guarantees the attempt cannot be left stuck in `running` when
     * handle() throws something neither catch block above anticipated
     * — this fires whenever the job ultimately fails from Laravel's own
     * perspective (an uncaught exception, since $tries = 1 means no
     * retry is attempted first).
     */
    public function failed(Throwable $exception): void
    {
        Log::error('JobAnalysis generation failed unexpectedly.', [
            'generation_attempt_id' => $this->attempt->id,
            'exception' => $exception::class,
        ]);

        $this->markFailed('unexpected_error', 'Job Analysis generation failed unexpectedly.');
    }

    /**
     * A development-stage diagnostic: an evidence-verification failure
     * (finish_reason not length, decode/validator both succeeded) is
     * currently unrecoverable once this process exits — $e->context
     * lives only in memory for the duration of this catch block. Logs
     * exactly the three fields needed to inspect the next occurrence
     * (which finding/evidence entry, and the excerpt itself) and
     * nothing else — never $e->context wholesale, never the finding it
     * belongs to (which includes that finding's own other evidence
     * entries), never the job description. Missing keys are omitted
     * rather than failing this handler, so a future change to
     * EvidenceExcerptVerifier's context shape can't crash failure
     * handling. See docs/job-analysis-generation.md "Async Job
     * Analysis".
     */
    private function logEvidenceVerificationDiagnostic(JobPosting $posting, InvalidJobAnalysisResponseException $e): void
    {
        $context = $e->context ?? [];
        $excerpt = $context['excerpt'] ?? null;

        Log::warning('JobAnalysis evidence verification failure diagnostic.', [
            'job_posting_id' => $posting->id,
            'generation_attempt_id' => $this->attempt->id,
            'finding_index' => $context['finding_index'] ?? null,
            'evidence_index' => $context['evidence_index'] ?? null,
            'excerpt' => is_string($excerpt) ? $excerpt : null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $diagnostics
     */
    private function markFailed(string $category, string $message, array $diagnostics = []): void
    {
        $this->attempt->update([
            'status' => GenerationStatus::Failed,
            'finished_at' => now(),
            'failure_category' => $category,
            'failure_message' => $message,
            ...$diagnostics,
        ]);
    }

    /**
     * @return array{0: string|null, 1: string|null}
     */
    private function splitGeneratedBy(?string $generatedBy): array
    {
        if ($generatedBy === null || ! str_contains($generatedBy, ':')) {
            return [null, null];
        }

        [$provider, $model] = explode(':', $generatedBy, 2);

        return [$provider, $model];
    }
}
