<?php

namespace App\Jobs;

use App\Enums\GenerationStatus;
use App\Exceptions\InvalidResumeVariantResponseException;
use App\Exceptions\ResumeGenerationProviderException;
use App\Models\GenerationAttempt;
use App\Models\JobMatch;
use App\Support\ResumeVariant\GenerateResumeVariant;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * A thin orchestration wrapper around the existing, unmodified
 * GenerateResumeVariant service — mirrors App\Jobs\GenerateJobAnalysisJob
 * and App\Jobs\GenerateJobMatchJob exactly. This class owns only the
 * GenerationAttempt lifecycle (queued -> running -> succeeded/failed),
 * never prompt/provider/validation logic. One attempt represents the
 * COMPLETE Selection -> validate -> Wording -> validate -> persist
 * pipeline, never two — see GenerationType::ResumeVariant's own
 * docblock. See docs/resume-variant-generation.md "Async Resume".
 *
 * Deliberately does NOT resolve CurrentCareerProfile — unlike Job
 * Match, GenerateResumeVariant::generateFull($jobMatch) already
 * resolves its own profile internally, from $jobMatch->careerProfile
 * (the profile frozen to that specific match), never from whichever
 * profile is currently "the" profile. Calling
 * CurrentCareerProfile::resolve() here would be dead code at best and
 * semantically wrong at worst in a hypothetical multi-profile future.
 *
 * $tries = 1 for the same reason as the other two jobs: the provider
 * transport already retries transient failures internally.
 *
 * Diagnostic limitation (deliberately not fixed this milestone — see
 * docs/resume-variant-generation.md "Async Resume" for the full
 * writeup): Resume Selection and Resume Wording share the same two
 * exception classes (ResumeGenerationProviderException,
 * InvalidResumeVariantResponseException), so which stage failed is
 * only recoverable for a *validation* failure, by inspecting the
 * exception message's existing "Resume Selection ..." / "Resume
 * Wording ..." prefix (ResumeSelectionResponseValidator/
 * ResumeWordingResponseValidator already write this prefix themselves
 * — nothing new is parsed out of provider content). A *provider*
 * failure carries no such prefix (its message comes straight from the
 * transport exception), so generation_stage is logged as null there —
 * this is an honest gap, not an oversight, and is not fixed here since
 * doing so would require changing GenerateResumeVariant/the provider
 * clients, which is out of scope for this migration. Field/path-level
 * validator diagnostics (which specific role/bullet-group/etc. failed)
 * remain unavailable for the same pre-existing reason identified before
 * this migration: both validators' throw sites build their message via
 * `implode(' ', $validator->errors()->all())`, which already discards
 * `$validator->errors()->keys()` before this job ever sees the
 * exception — not something this job can recover after the fact.
 */
final class GenerateResumeVariantJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(
        public readonly GenerationAttempt $attempt,
    ) {}

    public function handle(GenerateResumeVariant $generator): void
    {
        $this->attempt->update([
            'status' => GenerationStatus::Running,
            'started_at' => now(),
        ]);

        $jobMatch = $this->attempt->subject;

        if (! $jobMatch instanceof JobMatch) {
            $this->markFailed('unexpected_error', 'GenerationAttempt subject was not a JobMatch.');

            return;
        }

        try {
            $variant = $generator->generateFull($jobMatch);
        } catch (ResumeGenerationProviderException $e) {
            $diagnostics = $e->diagnostics;

            Log::warning('ResumeVariant generation failed.', [
                'job_match_id' => $jobMatch->id,
                'generation_attempt_id' => $this->attempt->id,
                'exception' => $e::class,
                'message' => $e->getMessage(),
                // See this job's own docblock: a provider failure's
                // message carries no stage prefix, so this is honestly
                // null rather than guessed at.
                'generation_stage' => null,
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
        } catch (InvalidResumeVariantResponseException $e) {
            Log::warning('ResumeVariant generation failed.', [
                'job_match_id' => $jobMatch->id,
                'generation_attempt_id' => $this->attempt->id,
                'exception' => $e::class,
                'message' => $e->getMessage(),
                'generation_stage' => $this->validationStage($e->getMessage()),
            ]);

            $this->markFailed('validation_error', $e->getMessage());

            return;
        }

        // Conservative, truthful metadata only: Resume has two
        // independently-configurable provider stages (Selection,
        // Wording), each already recorded on the ResumeVariant result
        // itself (selection_generated_by/wording_generated_by,
        // selection_prompt_version/wording_prompt_version) — merging
        // them into this attempt's singular provider/model/
        // prompt_version columns would misrepresent a run where the two
        // stages used different providers or models. schema_version is
        // the one field genuinely shared and single-valued across both
        // stages, so it alone is populated here. See
        // docs/resume-variant-generation.md "Async Resume".
        $this->attempt->update([
            'status' => GenerationStatus::Succeeded,
            'finished_at' => now(),
            'result_id' => $variant->id,
            'schema_version' => $variant->schema_version,
        ]);
    }

    /**
     * Guarantees the attempt cannot be left stuck in `running` when
     * handle() throws something neither catch block above anticipated.
     */
    public function failed(Throwable $exception): void
    {
        Log::error('ResumeVariant generation failed unexpectedly.', [
            'generation_attempt_id' => $this->attempt->id,
            'exception' => $exception::class,
        ]);

        $this->markFailed('unexpected_error', 'ResumeVariant generation failed unexpectedly.');
    }

    /**
     * Recovers which stage a validation failure came from purely by
     * reading the existing, already-public message prefix each
     * validator's own throw site writes
     * ('Resume Selection provider response failed validation: ...' /
     * 'Resume Wording provider response failed validation: ...') — no
     * validator change, no new parsing of provider content. Null if
     * neither prefix matches (should not happen given the two existing
     * validators, but never assumed).
     */
    private function validationStage(string $message): ?string
    {
        return match (true) {
            str_starts_with($message, 'Resume Selection') => 'selection',
            str_starts_with($message, 'Resume Wording') => 'wording',
            default => null,
        };
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
}
