export type ResumeCareerFactSummary = {
    key: string;
    statement: string;
    visibility: string;
    attribution: {
        employer: string | null;
        role: string | null;
        project: string | null;
    };
};

export type ResumeTargetTermUsage = {
    term: string;
    posture: 'direct' | 'qualified' | 'capability';
    relationship_phrase: string | null;
    job_analysis_finding_statement: string;
    evidence: ResumeCareerFactSummary[];
};

export type ResumeBullet = {
    id: number;
    project: string | null;
    text: string;
    citations: ResumeCareerFactSummary[];
    target_term_usages: ResumeTargetTermUsage[];
};

export type ResumeRoleGroup = {
    role_id: number;
    employer: string;
    display_title: string | null;
    start_year: number;
    start_month: number | null;
    end_year: number | null;
    end_month: number | null;
    bullets: ResumeBullet[];
};

export type ResumeSkill = {
    id: number;
    name: string;
    category: string;
};

export type ResumeEducation = {
    institution: string;
    degree: string;
    field_of_study: string | null;
    start_year: number | null;
    end_year: number | null;
};

export type ResumeVariantDetail = {
    id: number;
    generated_at: string | null;
    selection_generated_by: string | null;
    wording_generated_by: string | null;
    schema_version: string;
    selection_prompt_version: string;
    wording_prompt_version: string;
    summary: string | null;
    summary_evidence: ResumeCareerFactSummary[];
    summary_target_term_usages: ResumeTargetTermUsage[];
    experience: ResumeRoleGroup[];
    skills: ResumeSkill[];
    education: ResumeEducation[];
};

export type ResumeVariantJobContext = {
    id: number;
    company: string;
    title: string;
};

export type ResumeVariantAnalysisContext = {
    id: number;
};

export type ResumeVariantMatchContext = {
    id: number;
};

export type ResumeVariantShowProps = {
    job: ResumeVariantJobContext;
    analysis: ResumeVariantAnalysisContext;
    match: ResumeVariantMatchContext;
    resume: ResumeVariantDetail;
};
