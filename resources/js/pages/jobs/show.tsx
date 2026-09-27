import { Head, Link, useForm } from '@inertiajs/react';
import { GenerationStatus } from '@/components/generation-status';
import { useGenerationAttemptPolling } from '@/hooks/use-generation-attempt-polling';
import AppShell from '@/layouts/app-shell';
import { index as jobsIndex } from '@/routes/jobs';
import {
    show as analysesShow,
    store as analysesStore,
} from '@/routes/jobs/analyses';
import type { GenerationAttempt } from '@/types/generation-attempt';
import type { JobShowProps } from '@/types/job-posting';

function GenerateAnalysisAction({
    jobId,
    initialAttempt,
}: {
    jobId: number;
    initialAttempt: GenerationAttempt | null;
}) {
    const form = useForm({});
    const attempt = useGenerationAttemptPolling(initialAttempt);
    const isActive =
        attempt !== null &&
        (attempt.status === 'queued' || attempt.status === 'running');
    const disabled = form.processing || isActive;

    return (
        <div>
            <button
                type="button"
                disabled={disabled}
                onClick={() =>
                    form.post(analysesStore.url({ jobPosting: jobId }), {
                        showProgress: false,
                    })
                }
                className="inline-flex items-center rounded-md bg-neutral-900 px-4 py-2 text-sm font-medium text-white hover:bg-neutral-700 disabled:opacity-50 dark:bg-neutral-100 dark:text-neutral-900 dark:hover:bg-neutral-300"
            >
                {disabled ? 'Generating…' : 'Generate Analysis'}
            </button>
            <GenerationStatus
                active={isActive}
                label="Generating analysis"
                queued={attempt?.status === 'queued'}
                queuedLabel="Analysis queued…"
                startedAt={attempt?.started_at}
            />
            {attempt?.status === 'failed' && attempt.failure_message && (
                <p
                    role="alert"
                    className="mt-2 text-sm text-red-600 dark:text-red-400"
                >
                    {attempt.failure_message}
                </p>
            )}
        </div>
    );
}

export default function JobsShow({ job }: JobShowProps) {
    return (
        <AppShell>
            <Head title={`${job.title} at ${job.company}`} />

            <Link
                href={jobsIndex.url()}
                className="text-sm font-medium text-neutral-500 hover:text-neutral-700 dark:text-neutral-400 dark:hover:text-neutral-200"
            >
                ← Back to Jobs
            </Link>

            <div className="mt-4">
                <h1 className="text-xl font-semibold text-neutral-900 dark:text-neutral-100">
                    {job.title}
                </h1>
                <p className="text-sm text-neutral-600 dark:text-neutral-400">
                    {job.company}
                </p>

                <dl className="mt-3 flex flex-wrap gap-x-6 gap-y-1 text-xs text-neutral-500 dark:text-neutral-400">
                    {job.location && (
                        <div className="flex gap-1">
                            <dt className="font-medium text-neutral-400 dark:text-neutral-500">
                                Location:
                            </dt>
                            <dd>{job.location}</dd>
                        </div>
                    )}
                    {job.source_url && (
                        <div className="flex gap-1">
                            <dt className="font-medium text-neutral-400 dark:text-neutral-500">
                                Source:
                            </dt>
                            <dd>
                                <a
                                    href={job.source_url}
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    className="text-neutral-700 underline underline-offset-2 dark:text-neutral-300"
                                >
                                    {job.source_url}
                                </a>
                            </dd>
                        </div>
                    )}
                    <div className="flex gap-1">
                        <dt className="font-medium text-neutral-400 dark:text-neutral-500">
                            Captured:
                        </dt>
                        <dd>{job.captured_at}</dd>
                    </div>
                </dl>
            </div>

            <div className="mt-6">
                <div className="flex items-center justify-between">
                    <h2 className="text-sm font-semibold text-neutral-900 dark:text-neutral-100">
                        Job Analysis
                    </h2>
                    <GenerateAnalysisAction
                        jobId={job.id}
                        initialAttempt={job.latest_job_analysis_attempt}
                    />
                </div>

                {job.analyses.length === 0 ? (
                    <p className="mt-2 text-xs text-neutral-400 dark:text-neutral-500">
                        No analysis has been generated yet.
                    </p>
                ) : (
                    <ul className="mt-2 divide-y divide-neutral-200 rounded-lg border border-neutral-200 bg-white dark:divide-neutral-800 dark:border-neutral-800 dark:bg-neutral-900">
                        {job.analyses.map((analysis) => (
                            <li key={analysis.id}>
                                <Link
                                    href={analysesShow.url({
                                        jobPosting: job.id,
                                        jobAnalysis: analysis.id,
                                    })}
                                    className="flex items-center justify-between px-4 py-3 text-sm hover:bg-neutral-50 dark:hover:bg-neutral-800/50"
                                >
                                    <span className="text-neutral-700 dark:text-neutral-300">
                                        {analysis.generated_at ?? '—'}
                                    </span>
                                    <span className="text-xs text-neutral-400 dark:text-neutral-500">
                                        {analysis.overall_seniority ??
                                            'unspecified'}{' '}
                                        · {analysis.findings_count ?? 0} finding
                                        {analysis.findings_count === 1
                                            ? ''
                                            : 's'}
                                    </span>
                                </Link>
                            </li>
                        ))}
                    </ul>
                )}
            </div>

            <div className="mt-6">
                <h2 className="text-sm font-semibold text-neutral-900 dark:text-neutral-100">
                    Original Job Description
                </h2>
                <p className="mt-1 text-xs text-neutral-400 dark:text-neutral-500">
                    Captured verbatim at intake — not an analysis or summary.
                </p>
                <div className="mt-2 rounded-lg border border-neutral-200 bg-white p-4 dark:border-neutral-800 dark:bg-neutral-900">
                    <p className="text-sm leading-relaxed whitespace-pre-wrap text-neutral-800 dark:text-neutral-200">
                        {job.description}
                    </p>
                </div>
            </div>
        </AppShell>
    );
}
