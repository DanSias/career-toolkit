import { Head, Link } from '@inertiajs/react';
import AppShell from '@/layouts/app-shell';
import { index as jobsIndex } from '@/routes/jobs';
import type { JobShowProps } from '@/types/job-posting';

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
