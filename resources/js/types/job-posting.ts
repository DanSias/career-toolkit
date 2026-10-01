import type { InspectionSummary } from '@/types/application-inspection';
import type { GenerationAttempt } from '@/types/generation-attempt';

/**
 * Matches JobPostingController::transformDiscoveryMetadata() exactly.
 * source is always present ('manual' for hand-created postings);
 * everything else is null unless discovery/canonical enrichment
 * populated it.
 */
export type DescriptionCompleteness = 'complete' | 'preview' | 'unknown';

export type JobPostingDiscoveryMetadata = {
    source: string;
    canonical_source: string | null;
    discovered_at: string | null;
    remote_status: string | null;
    employment_type: string | null;
    compensation_min: number | null;
    compensation_max: number | null;
    compensation_currency: string | null;
    description_completeness: DescriptionCompleteness;
};

export type JobPostingSummary = {
    id: number;
    company: string;
    title: string;
    location: string | null;
    has_source_url: boolean;
    source_url: string | null;
    captured_at: string | null;
    inspection: InspectionSummary;
    discovery: JobPostingDiscoveryMetadata;
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
    inspection: InspectionSummary;
    discovery: JobPostingDiscoveryMetadata;
};

export type JobsIndexFilters = {
    q: string;
    status: 'all' | 'not_inspected' | 'inspected';
};

export type JobsIndexProps = {
    jobs: JobPostingSummary[];
    filters: JobsIndexFilters;
};

export type JobShowProps = {
    job: JobPostingDetail;
};
