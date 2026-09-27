/**
 * Matches App\Support\GenerationAttempt\PresentGenerationAttempt's
 * shape exactly — the one safe JSON representation of a
 * GenerationAttempt used both in initial page props and by the polling
 * endpoint. See docs/job-analysis-generation.md "Async Job Analysis".
 */
export type GenerationAttemptStatus =
    | 'queued'
    | 'running'
    | 'succeeded'
    | 'failed';

export type GenerationAttempt = {
    id: number;
    status: GenerationAttemptStatus;
    generation_type: 'job_analysis' | 'job_match' | 'resume_variant';
    queued_at: string | null;
    started_at: string | null;
    finished_at: string | null;
    failure_message: string | null;
    result_url: string | null;
};
