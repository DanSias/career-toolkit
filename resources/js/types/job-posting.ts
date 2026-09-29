import type { GenerationAttempt } from '@/types/generation-attempt';

export type JobPostingSummary = {
    id: number;
    company: string;
    title: string;
    location: string | null;
    has_source_url: boolean;
    source_url: string | null;
    captured_at: string | null;
};

export type JobAnalysisSummary = {
    id: number;
    generated_at: string | null;
    overall_seniority: string | null;
    findings_count: number | null;
};

export type JobPostingDetail = {
    id: number;
    company: string;
    title: string;
    location: string | null;
    source_url: string | null;
    captured_at: string | null;
    description: string;
    analyses: JobAnalysisSummary[];
    latest_job_analysis_attempt: GenerationAttempt | null;
    application_id: number | null;
};

export type JobsIndexProps = {
    jobs: JobPostingSummary[];
};

export type JobShowProps = {
    job: JobPostingDetail;
};
