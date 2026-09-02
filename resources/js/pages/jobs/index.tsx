import { Head, Link } from '@inertiajs/react';
import AppShell from '@/layouts/app-shell';
import { create as createJob, show as showJob } from '@/routes/jobs';
import type { JobsIndexProps } from '@/types/job-posting';

export default function JobsIndex({ jobs }: JobsIndexProps) {
    return (
        <AppShell>
            <Head title="Jobs" />

            <div className="flex flex-wrap items-center justify-between gap-3">
                <h1 className="text-xl font-semibold text-neutral-900 dark:text-neutral-100">
                    Jobs
                </h1>
                <Link
                    href={createJob.url()}
                    className="inline-flex items-center rounded-md bg-neutral-900 px-3 py-1.5 text-sm font-medium text-white hover:bg-neutral-700 dark:bg-neutral-100 dark:text-neutral-900 dark:hover:bg-neutral-300"
                >
                    Add Job
                </Link>
            </div>

            {jobs.length === 0 ? (
                <div className="mt-6 rounded-lg border border-dashed border-neutral-300 p-8 text-center dark:border-neutral-700">
                    <p className="text-sm text-neutral-500 dark:text-neutral-400">
                        No jobs captured yet.
                    </p>
                    <Link
                        href={createJob.url()}
                        className="mt-3 inline-flex items-center text-sm font-medium text-neutral-900 underline underline-offset-2 dark:text-neutral-100"
                    >
                        Add your first job
                    </Link>
                </div>
            ) : (
                <ul className="mt-6 divide-y divide-neutral-200 rounded-lg border border-neutral-200 bg-white dark:divide-neutral-800 dark:border-neutral-800 dark:bg-neutral-900">
                    {jobs.map((job) => (
                        <li key={job.id}>
                            <Link
                                href={showJob.url(job.id)}
                                className="flex flex-wrap items-center justify-between gap-2 p-4 hover:bg-neutral-50 dark:hover:bg-neutral-800/50"
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
                                        {job.has_source_url && (
                                            <span>Has source link · </span>
                                        )}
                                        Captured {job.captured_at}
                                    </p>
                                </div>
                            </Link>
                        </li>
                    ))}
                </ul>
            )}
        </AppShell>
    );
}
