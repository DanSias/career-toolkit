import { Head, Link, useForm } from '@inertiajs/react';
import { GenerationStatus } from '@/components/generation-status';
import { WaitingForWorkerNotice } from '@/components/worker-status-badge';
import { useApplicationInspectionPolling } from '@/hooks/use-application-inspection-polling';
import { useWorkerStatusPolling } from '@/hooks/use-worker-status-polling';
import AppShell from '@/layouts/app-shell';
import { show as jobsShow } from '@/routes/jobs';
import { store as inspectStore } from '@/routes/jobs/applications';
import type {
    ApplicationQuestionView,
    ApplicationShowProps,
} from '@/types/application-inspection';

const OPTIONS_COLLAPSE_THRESHOLD = 6;

function OptionsList({ options }: { options: string[] }) {
    if (options.length === 0) {
        return null;
    }

    if (options.length <= OPTIONS_COLLAPSE_THRESHOLD) {
        return (
            <p className="mt-1 text-xs text-neutral-500 dark:text-neutral-400">
                Options: {options.join(', ')}
            </p>
        );
    }

    return (
        <details className="mt-1">
            <summary className="cursor-pointer text-xs text-neutral-500 dark:text-neutral-400">
                {options.length} options
            </summary>
            <p className="mt-1 text-xs text-neutral-500 dark:text-neutral-400">
                {options.join(', ')}
            </p>
        </details>
    );
}

function RequiredBadge({ required }: { required: boolean | null }) {
    if (required === null) {
        return (
            <span className="text-xs text-neutral-400 dark:text-neutral-500">
                unknown
            </span>
        );
    }

    return (
        <span
            className={
                required
                    ? 'text-xs font-medium text-orange-600 dark:text-orange-400'
                    : 'text-xs text-neutral-400 dark:text-neutral-500'
            }
        >
            {required ? 'required' : 'optional'}
        </span>
    );
}

function QuestionRow({ question }: { question: ApplicationQuestionView }) {
    const unresolved = question.label_source === 'unresolved';

    return (
        <li className="px-4 py-3">
            <div className="flex items-start justify-between gap-4">
                <div>
                    <span className="mr-2 text-xs text-neutral-400 dark:text-neutral-500">
                        #{question.position}
                    </span>
                    {unresolved ? (
                        <span className="text-sm text-neutral-400 italic dark:text-neutral-500">
                            Unresolved field
                        </span>
                    ) : (
                        <span className="text-sm text-neutral-800 dark:text-neutral-200">
                            {question.raw_label}
                        </span>
                    )}
                    {question.section && (
                        <span className="ml-2 text-xs text-neutral-400 dark:text-neutral-500">
                            ({question.section})
                        </span>
                    )}
                </div>
                <div className="flex shrink-0 items-center gap-3">
                    <span className="text-xs text-neutral-500 dark:text-neutral-400">
                        {question.control_type}
                    </span>
                    <RequiredBadge required={question.required} />
                </div>
            </div>
            {question.options && <OptionsList options={question.options} />}
        </li>
    );
}

function InspectionResultSection({
    inspection,
}: {
    inspection: ApplicationShowProps['inspection'];
}) {
    const result = inspection.latest_result;

    if (!result) {
        return null;
    }

    return (
        <div className="mt-4">
            <div className="flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-neutral-500 dark:text-neutral-400">
                <span>
                    ATS:{' '}
                    <span className="font-medium text-neutral-700 dark:text-neutral-300">
                        {result.ats_detected ?? 'unknown'}
                    </span>
                </span>
                <span>
                    Outcome:{' '}
                    <span className="font-medium text-neutral-700 dark:text-neutral-300">
                        {result.inspection_outcome}
                    </span>
                </span>
                <span>{result.field_count} fields</span>
                {result.unresolved_count > 0 && (
                    <span>{result.unresolved_count} unresolved</span>
                )}
                {result.finished_at && (
                    <span>
                        Last inspected{' '}
                        {new Date(result.finished_at).toLocaleString()}
                    </span>
                )}
            </div>

            {result.warnings.length > 0 && (
                <ul className="mt-2 space-y-0.5 text-xs text-amber-600 dark:text-amber-400">
                    {result.warnings.map((warning, index) => (
                        <li key={index}>⚠ {warning}</li>
                    ))}
                </ul>
            )}

            {result.inspection_outcome === 'unsupported' ? (
                <p className="mt-3 text-sm text-neutral-500 dark:text-neutral-400">
                    This application isn't on a currently supported ATS
                    (Greenhouse only). Nothing was extracted.
                </p>
            ) : (
                <ul className="mt-3 divide-y divide-neutral-200 rounded-lg border border-neutral-200 bg-white dark:divide-neutral-800 dark:border-neutral-800 dark:bg-neutral-900">
                    {result.questions.map((question) => (
                        <QuestionRow
                            key={question.position}
                            question={question}
                        />
                    ))}
                </ul>
            )}
        </div>
    );
}

function InspectApplicationAction({
    jobPostingId,
    inspection,
}: {
    jobPostingId: number;
    inspection: ApplicationShowProps['inspection'];
}) {
    const form = useForm({});
    const state = useApplicationInspectionPolling(
        inspection.application_id,
        inspection,
    );
    const workerStatus = useWorkerStatusPolling();
    const run = state.workflow_run;
    const isActive = run?.status === 'pending' || run?.status === 'running';
    const disabled = form.processing || isActive;

    return (
        <div>
            <div className="flex items-center gap-3">
                <button
                    type="button"
                    disabled={disabled}
                    onClick={() =>
                        form.post(
                            inspectStore.url({ jobPosting: jobPostingId }),
                            {
                                showProgress: false,
                            },
                        )
                    }
                    className="inline-flex items-center rounded-md bg-neutral-900 px-4 py-2 text-sm font-medium text-white hover:bg-neutral-700 disabled:opacity-50 dark:bg-neutral-100 dark:text-neutral-900 dark:hover:bg-neutral-300"
                >
                    {isActive
                        ? 'Inspecting…'
                        : run?.status === 'failed'
                          ? 'Retry Inspection'
                          : 'Inspect Application'}
                </button>
                {run && (
                    <span className="text-xs text-neutral-500 dark:text-neutral-400">
                        {run.status === 'succeeded' &&
                            'Last inspection succeeded'}
                        {run.status === 'failed' && 'Last inspection failed'}
                        {run.status === 'pending' && 'Queued…'}
                        {run.status === 'running' && 'Inspecting…'}
                    </span>
                )}
            </div>

            <GenerationStatus
                active={isActive}
                label="Inspecting application"
                queued={run?.status === 'pending'}
                queuedLabel="Inspection queued…"
                startedAt={run?.started_at}
            />

            {run?.status === 'failed' && run.failure_message && (
                <p
                    role="alert"
                    className="mt-2 text-sm text-red-600 dark:text-red-400"
                >
                    {run.failure_message}
                </p>
            )}

            {isActive && <WaitingForWorkerNotice status={workerStatus} />}

            <InspectionResultSection inspection={state} />
        </div>
    );
}

export default function ApplicationShow({
    application,
    inspection,
}: ApplicationShowProps) {
    return (
        <AppShell>
            <Head
                title={`Application — ${application.job_posting.title} at ${application.job_posting.company}`}
            />

            <Link
                href={jobsShow.url({ jobPosting: application.job_posting.id })}
                className="text-sm font-medium text-neutral-500 hover:text-neutral-700 dark:text-neutral-400 dark:hover:text-neutral-200"
            >
                ← Back to Opportunity
            </Link>

            <div className="mt-4">
                <h1 className="text-xl font-semibold text-neutral-900 dark:text-neutral-100">
                    {application.job_posting.title}
                </h1>
                <p className="text-sm text-neutral-600 dark:text-neutral-400">
                    {application.job_posting.company}
                </p>
            </div>

            <div className="mt-6">
                <h2 className="text-sm font-semibold text-neutral-900 dark:text-neutral-100">
                    Application Inspection
                </h2>
                <p className="mt-1 text-xs text-neutral-400 dark:text-neutral-500">
                    Read-only — extracts the application's fields and questions
                    without filling or submitting anything.
                </p>
                <div className="mt-3">
                    <InspectApplicationAction
                        jobPostingId={application.job_posting.id}
                        inspection={inspection}
                    />
                </div>
            </div>
        </AppShell>
    );
}
