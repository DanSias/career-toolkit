/**
 * Matches App\Support\ApplicationInspection\PresentApplicationInspection's
 * shape exactly — the one safe JSON representation used both in
 * ApplicationController::show()'s initial page props and by the
 * polling endpoint. See docs/application-inspector.md.
 */
export type WorkflowRunStatus =
    | 'pending'
    | 'running'
    | 'succeeded'
    | 'failed'
    | 'cancelled';

export type ApplicationQuestionView = {
    position: number;
    raw_label: string | null;
    label_source: string;
    control_type: string;
    required: boolean | null;
    options: string[] | null;
    section: string | null;
    extraction_source: string;
};

export type InspectionWorkflowRun = {
    id: number;
    status: WorkflowRunStatus;
    failure_message: string | null;
    started_at: string | null;
    finished_at: string | null;
};

export type InspectionResult = {
    agent_run_id: number;
    ats_detected: string | null;
    inspection_outcome: string | null;
    finished_at: string | null;
    warnings: string[];
    field_count: number;
    unresolved_count: number;
    questions: ApplicationQuestionView[];
};

export type ApplicationInspectionState = {
    application_id: number;
    workflow_run: InspectionWorkflowRun | null;
    latest_result: InspectionResult | null;
};

export type ApplicationDetail = {
    id: number;
    job_posting: {
        id: number;
        company: string;
        title: string;
        source_url: string | null;
    };
};

export type ApplicationShowProps = {
    application: ApplicationDetail;
    inspection: ApplicationInspectionState;
};

/**
 * The compact, derived inspection-state summary shown on the
 * Opportunities index/detail — see
 * App\Support\ApplicationInspection\SummarizeInspectionStates. Distinct
 * from ApplicationInspectionState above: this never carries the actual
 * question list, only enough to render a status badge.
 */
export type InspectionState =
    | 'not_inspected'
    | 'queued'
    | 'running'
    | 'inspected'
    | 'failed'
    | 'unsupported';

export type InspectionSummary = {
    state: InspectionState;
    application_id: number | null;
    field_count: number | null;
    latest_attempt_failed: boolean;
};
