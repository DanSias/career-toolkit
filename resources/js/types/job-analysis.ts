import type { JobMatchSummary } from '@/types/job-match';

export type JobAnalysisEvidence = {
    excerpt: string;
    source_section: string | null;
    source_locator: string | null;
};

export type JobAnalysisFinding = {
    id: number;
    statement: string;
    label: string | null;
    basis: string;
    requirement_strength: string | null;
    emphasis: string | null;
    maturity: string | null;
    years_experience_min: number | null;
    years_experience_max: number | null;
    recency_requirement: string | null;
    time_horizon: string | null;
    notes: string | null;
    evidence: JobAnalysisEvidence[];
};

export type JobAnalysisCategoryGroup = {
    category: string;
    findings: JobAnalysisFinding[];
};

export type JobAnalysisDetail = {
    id: number;
    generated_at: string | null;
    generated_by: string | null;
    schema_version: string;
    prompt_version: string | null;
    role_summary: string;
    overall_seniority: string | null;
    seniority_rationale: string | null;
    categories: JobAnalysisCategoryGroup[];
    matches: JobMatchSummary[];
};

export type JobAnalysisJobContext = {
    id: number;
    company: string;
    title: string;
};

export type JobAnalysisShowProps = {
    job: JobAnalysisJobContext;
    analysis: JobAnalysisDetail;
};
