<?php

namespace App\Jobs;

use App\Enums\GenerationStatus;
use App\Exceptions\InvalidJobMatchResponseException;
use App\Exceptions\JobMatchProviderException;
use App\Models\GenerationAttempt;
use App\Models\JobAnalysis;
use App\Support\CurrentCareerProfile;
use App\Support\JobMatch\GenerateJobMatch;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * A thin orchestration wrapper around the existing, unmodified
 * GenerateJobMatch service — mirrors App\Jobs\GenerateJobAnalysisJob
 * exactly. This class owns only the GenerationAttempt lifecycle
 * (queued -> running -> succeeded/failed), never prompt/provider/
 * validation logic. See docs/job-match-generation.md "Async Job Match".
 *
 * $tries = 1 for the same reason as GenerateJobAnalysisJob: the
 * provider transport already retries transient failures internally,
 * and an outer queue retry on top of that would silently multiply real
 * provider calls for a single logical attempt.
 *
 * CurrentCareerProfile::resolve() is called here, inside handle() —
 * not captured at dispatch time — for the same reason the subject is
 * always re-resolved fresh: this app has exactly one profile,
 * deterministically resolved, so there is nothing meaningful to
 * capture ahead of time, and resolving it fresh avoids ever acting on
 * a stale reference.
 *
 * Only two exception types are caught inside handle() and translated
 * into a `failed` GenerationAttempt without rethrowing:
 * JobMatchProviderException and InvalidJobMatchResponseException — the
 * same two JobMatchController::store() previously treated as ordinary,
 * expected generation-failure outcomes. Anything else is deliberately
 * left uncaught, so it propagates to Laravel's queue machinery;
 * failed() below guarantees the attempt still can't be left stuck in
 * `running` when that happens.
 */
final class GenerateJobMatchJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(
        public readonly GenerationAttempt $attempt,
    ) {}

    public function handle(GenerateJobMatch $generator): void
    {
        $this->attempt->update([
            'status' => GenerationStatus::Running,
            'started_at' => now(),
        ]);

        $analysis = $this->attempt->subject;

        if (! $analysis instanceof JobAnalysis) {
            $this->markFailed('unexpected_error', 'GenerationAttempt subject was not a JobAnalysis.');

            return;
        }

        $profile = CurrentCareerProfile::resolve();

        try {
            $match = $generator->generate($analysis, $profile);
        } catch (JobMatchProviderException $e) {
            $diagnostics = $e->diagnostics;

            Log::warning('JobMatch generation failed.', [
                'job_analysis_id' => $analysis->id,
                'career_profile_id' => $profile->id,
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
        } catch (InvalidJobMatchResponseException $e) {
            Log::warning('JobMatch generation failed.', [
                'job_analysis_id' => $analysis->id,
                'career_profile_id' => $profile->id,
                'generation_attempt_id' => $this->attempt->id,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            $this->markFailed('validation_error', $e->getMessage());

            return;
        }

        [$provider, $model] = $this->splitGeneratedBy($match->generated_by);

        $this->attempt->update([
            'status' => GenerationStatus::Succeeded,
            'finished_at' => now(),
            'result_id' => $match->id,
            'provider' => $provider,
            'model' => $model,
            'prompt_version' => $match->prompt_version,
            'schema_version' => $match->schema_version,
        ]);
    }

    /**
     * Guarantees the attempt cannot be left stuck in `running` when
     * handle() throws something neither catch block above anticipated.
     */
    public function failed(Throwable $exception): void
    {
        Log::error('JobMatch generation failed unexpectedly.', [
            'generation_attempt_id' => $this->attempt->id,
            'exception' => $exception::class,
        ]);

        $this->markFailed('unexpected_error', 'Job Match generation failed unexpectedly.');
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
