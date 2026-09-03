export type JobMatchSummary = {
    id: number;
    generated_at: string | null;
    findings_count: number | null;
};

export type CareerFactMatchDetail = {
    relationship: string;
    rationale: string | null;
    statement: string;
    visibility: string;
    attribution: {
        employer: string | null;
        role: string | null;
        project: string | null;
    };
    role_dates: {
        start_year: number | null;
        start_month: number | null;
        end_year: number | null;
        end_month: number | null;
    } | null;
};

export type EducationMatchDetail = {
    relationship: string;
    rationale: string | null;
    institution: string;
    degree: string;
    field_of_study: string | null;
    start_year: number | null;
    end_year: number | null;
};

export type JobMatchFindingDetail = {
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
    coverage: string;
    coverage_rationale: string | null;
    career_fact_matches: CareerFactMatchDetail[];
    education_matches: EducationMatchDetail[];
};

export type JobMatchCategoryGroup = {
    category: string;
    findings: JobMatchFindingDetail[];
};

export type ResumeVariantSummary = {
    id: number;
    generated_at: string | null;
};

export type DiscoveryPreflightCandidate = {
    job_analysis_finding_id: number;
    term: string;
    prompt: string;
    tier: number;
};

export type JobMatchDetail = {
    id: number;
    generated_at: string | null;
    generated_by: string | null;
    schema_version: string;
    prompt_version: string | null;
    categories: JobMatchCategoryGroup[];
    resume_variants: ResumeVariantSummary[];
    discovery_preflight: DiscoveryPreflightCandidate[];
};

export type JobMatchJobContext = {
    id: number;
    company: string;
    title: string;
};

export type JobMatchAnalysisContext = {
    id: number;
};

export type JobMatchShowProps = {
    job: JobMatchJobContext;
    analysis: JobMatchAnalysisContext;
    match: JobMatchDetail;
};
