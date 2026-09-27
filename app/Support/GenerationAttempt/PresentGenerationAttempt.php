<?php

namespace App\Support\GenerationAttempt;

use App\Enums\GenerationStatus;
use App\Enums\GenerationType;
use App\Models\GenerationAttempt;

/**
 * The one safe, minimal JSON shape a browser is ever handed for a
 * GenerationAttempt — used identically by the polling endpoint and by
 * JobPostingController's initial page props, so the frontend never
 * sees two different shapes for the same concept. Deliberately exposes
 * nothing beyond lifecycle/timing/failure-message/result-link: no
 * token counts, no provider/model, no prompt/schema version — those
 * stay operational-only for now (log/database inspection), not because
 * they're unsafe, but because nothing in the UI needs them yet. See
 * docs/job-analysis-generation.md "Durable generation attempts
 * (foundation)".
 */
final class PresentGenerationAttempt
{
    /**
     * @return array{id: int, status: string, generation_type: string, queued_at: string|null, started_at: string|null, finished_at: string|null, failure_message: string|null, result_url: string|null}
     */
    public function present(GenerationAttempt $attempt): array
    {
        return [
            'id' => $attempt->id,
            'status' => $attempt->status->value,
            'generation_type' => $attempt->generation_type->value,
            'queued_at' => $attempt->queued_at?->toIso8601String(),
            'started_at' => $attempt->started_at?->toIso8601String(),
            'finished_at' => $attempt->finished_at?->toIso8601String(),
            'failure_message' => $attempt->status === GenerationStatus::Failed ? $attempt->failure_message : null,
            'result_url' => $this->resultUrl($attempt),
        ];
    }

    private function resultUrl(GenerationAttempt $attempt): ?string
    {
        if ($attempt->status !== GenerationStatus::Succeeded || $attempt->result_id === null) {
            return null;
        }

        // Only job_analysis has a real destination today — Job Match
        // and Resume generation aren't migrated to this flow yet (see
        // docs/job-analysis-generation.md), so there is deliberately no
        // route for their result types here until that happens.
        return match ($attempt->generation_type) {
            GenerationType::JobAnalysis => route('jobs.analyses.show', [
                'jobPosting' => $attempt->subject_id,
                'jobAnalysis' => $attempt->result_id,
            ]),
            default => null,
        };
    }
}
