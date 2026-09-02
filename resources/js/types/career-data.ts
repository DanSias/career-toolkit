export type Verification =
    | 'verified'
    | 'strongly_supported'
    | 'needs_confirmation';

export type Visibility = 'public' | 'restricted' | 'private';

export type SkillCategory =
    | 'build_technology'
    | 'platform_integration'
    | 'capability'
    | 'practice';

export type SkillRef = {
    slug: string;
    name: string;
    category: SkillCategory;
};

export type FactSummary = {
    key: string;
    fact_type: string;
    statement: string;
    verification: Verification;
    visibility: Visibility;
    has_metric: boolean;
};

export type ProjectSummary = {
    slug: string;
    name: string;
    description: string | null;
    visibility: Visibility | null;
    fact_count: number;
    skills: SkillRef[];
};

export type RoleSummary = {
    id: number;
    title: string;
    date_label: string;
    is_current: boolean;
    fact_count: number;
    projects: ProjectSummary[];
};

export type EmployerSummary = {
    id: number;
    name: string;
    short_name: string | null;
    description: string | null;
    fact_count: number;
    roles: RoleSummary[];
};

export type EducationSummary = {
    id: number;
    institution: string;
    degree: string;
    field_of_study: string | null;
    start_year: number | null;
    end_year: number | null;
};

export type SkillSummary = {
    slug: string;
    name: string;
    category: SkillCategory;
    career_fact_count: number;
    project_count: number;
};

export type CareerDataIndexProps = {
    profile: {
        name: string;
        facts: FactSummary[];
    } | null;
    employers: EmployerSummary[];
    education: EducationSummary[];
    skills: SkillSummary[];
};

export type MetricDetail = {
    display: string;
    value: number;
    value_max: number | null;
    is_range: boolean;
    unit: string;
    comparator: string | null;
    scope_note: string | null;
    guardrail: string | null;
};

export type EvidenceDetail = {
    source: string;
    document: string | null;
    path: string | null;
    section: string | null;
    locator: string | null;
    quoted_text: string | null;
    confirmed_at: string | null;
    note: string | null;
};

export type AttributionType =
    | 'career_profile'
    | 'employer'
    | 'role'
    | 'project'
    | 'unknown';

export type FactDetail = {
    key: string;
    fact_type: string;
    statement: string;
    verification: Verification;
    visibility: Visibility;
    notes: string | null;
    attribution: {
        type: AttributionType;
        path: string[];
    };
    metric: MetricDetail | null;
    skills: SkillRef[];
    evidence: EvidenceDetail[];
};

export type CareerDataFactProps = {
    fact: FactDetail;
};
