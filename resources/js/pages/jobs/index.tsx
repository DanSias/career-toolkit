import { Head, Link, router, useForm } from '@inertiajs/react';
import { type FormEvent, useState } from 'react';
import { InspectionStateBadge } from '@/components/badges';
import { cn } from '@/lib/utils';
import {
    index as jobsIndex,
    create as createJob,
    show as showJob,
} from '@/routes/jobs';
import { store as inspectStore } from '@/routes/jobs/applications';
import { show as applicationsShow } from '@/routes/applications';
import AppShell from '@/layouts/app-shell';
import type { JobPostingSummary, JobsIndexProps } from '@/types/job-posting';

const STATUS_TABS: {
    value: 'all' | 'not_inspected' | 'inspected';
    label: string;
}[] = [
    { value: 'all', label: 'All' },
    { value: 'not_inspected', label: 'Not inspected' },
    { value: 'inspected', label: 'Inspected' },
];

function InspectAction({ job }: { job: JobPostingSummary }) {
    const form = useForm({});

    if (!job.has_source_url) {
        return null;
    }

    if (job.inspection.application_id !== null) {
        return (
            <Link
                href={applicationsShow.url({
                    application: job.inspection.application_id,
                })}
                onClick={(e) => e.stopPropagation()}
                className="inline-flex items-center rounded-md border border-neutral-300 px-2.5 py-1 text-xs font-medium text-neutral-700 hover:bg-neutral-50 dark:border-neutral-700 dark:text-neutral-300 dark:hover:bg-neutral-800"
            >
                View results
            </Link>
        );
    }

    return (
        <button
            type="button"
            disabled={form.processing}
            onClick={(e) => {
                e.preventDefault();
                e.stopPropagation();
                form.post(inspectStore.url({ jobPosting: job.id }), {
                    showProgress: false,
                });
            }}
            className="inline-flex items-center rounded-md border border-neutral-300 px-2.5 py-1 text-xs font-medium text-neutral-700 hover:bg-neutral-50 disabled:opacity-50 dark:border-neutral-700 dark:text-neutral-300 dark:hover:bg-neutral-800"
        >
            Inspect Application
        </button>
    );
}

export default function JobsIndex({ jobs, filters }: JobsIndexProps) {
    const [q, setQ] = useState(filters.q);

    const applyFilters = (next: Partial<typeof filters>) => {
        router.get(
            jobsIndex.url(),
            { q, status: filters.status, ...next },
            { preserveState: true, replace: true },
        );
    };

    const onSearchSubmit = (e: FormEvent) => {
        e.preventDefault();
        applyFilters({ q });
    };

    return (
        <AppShell>
            <Head title="Opportunities" />

            <div className="flex flex-wrap items-center justify-between gap-3">
                <h1 className="text-xl font-semibold text-neutral-900 dark:text-neutral-100">
                    Opportunities
                </h1>
                <Link
                    href={createJob.url()}
                    className="inline-flex items-center rounded-md bg-neutral-900 px-3 py-1.5 text-sm font-medium text-white hover:bg-neutral-700 dark:bg-neutral-100 dark:text-neutral-900 dark:hover:bg-neutral-300"
                >
                    Add Job
                </Link>
            </div>

            <div className="mt-4 flex flex-wrap items-center justify-between gap-3">
                <form
                    onSubmit={onSearchSubmit}
                    className="flex items-center gap-2"
                >
                    <input
                        type="text"
                        value={q}
                        onChange={(e) => setQ(e.target.value)}
                        placeholder="Search title or company…"
                        className="w-64 rounded-md border border-neutral-300 bg-white px-3 py-1.5 text-sm text-neutral-900 placeholder:text-neutral-400 focus:border-neutral-500 focus:outline-none dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-100"
                    />
                    <button
                        type="submit"
                        className="rounded-md border border-neutral-300 px-3 py-1.5 text-sm font-medium text-neutral-700 hover:bg-neutral-50 dark:border-neutral-700 dark:text-neutral-300 dark:hover:bg-neutral-800"
                    >
                        Search
                    </button>
                </form>

                <div className="flex items-center gap-1 text-sm">
                    {STATUS_TABS.map((tab) => (
                        <button
                            key={tab.value}
                            type="button"
                            onClick={() => applyFilters({ status: tab.value })}
                            className={cn(
                                'rounded-md px-2.5 py-1 font-medium',
                                filters.status === tab.value
                                    ? 'bg-neutral-900 text-white dark:bg-neutral-100 dark:text-neutral-900'
                                    : 'text-neutral-500 hover:bg-neutral-100 dark:text-neutral-400 dark:hover:bg-neutral-800',
                            )}
                        >
                            {tab.label}
                        </button>
                    ))}
                </div>
            </div>

            {jobs.length === 0 ? (
                <div className="mt-6 rounded-lg border border-dashed border-neutral-300 p-8 text-center dark:border-neutral-700">
                    <p className="text-sm text-neutral-500 dark:text-neutral-400">
                        {filters.q || filters.status !== 'all'
                            ? 'No opportunities match this search/filter.'
                            : 'No jobs captured yet.'}
                    </p>
                    {!filters.q && filters.status === 'all' && (
                        <Link
                            href={createJob.url()}
                            className="mt-3 inline-flex items-center text-sm font-medium text-neutral-900 underline underline-offset-2 dark:text-neutral-100"
                        >
                            Add your first job
                        </Link>
                    )}
                </div>
            ) : (
                <ul className="mt-6 divide-y divide-neutral-200 rounded-lg border border-neutral-200 bg-white dark:divide-neutral-800 dark:border-neutral-800 dark:bg-neutral-900">
                    {jobs.map((job) => (
                        <li key={job.id}>
                            <Link
                                href={showJob.url(job.id)}
                                className="flex flex-wrap items-center justify-between gap-3 p-4 hover:bg-neutral-50 dark:hover:bg-neutral-800/50"
                            >
                                <div>
                                    <p className="text-sm font-medium text-neutral-900 dark:text-neutral-100">
                                        {job.title}{' '}
                                        <span className="font-normal text-neutral-500 dark:text-neutral-400">
                                            at {job.company}
                                        </span>
                                    </p>
                                    <p className="mt-0.5 text-xs text-neutral-500 dark:text-neutral-400">
                                        {job.location && (
                                            <span>{job.location} · </span>
                                        )}
                                        Captured {job.captured_at}
                                    </p>
                                </div>
                                <div className="flex items-center gap-3">
                                    <InspectionStateBadge
                                        summary={job.inspection}
                                    />
                                    <InspectAction job={job} />
                                </div>
                            </Link>
                        </li>
                    ))}
                </ul>
            )}
        </AppShell>
    );
}
