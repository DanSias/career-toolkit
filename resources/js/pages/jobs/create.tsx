import { Head, Link, useForm } from '@inertiajs/react';
import type { FormEventHandler } from 'react';
import AppShell from '@/layouts/app-shell';
import { index as jobsIndex, store } from '@/routes/jobs';

const inputClass =
    'mt-1 block w-full rounded-md border border-neutral-300 bg-white px-3 py-2 text-sm text-neutral-900 shadow-sm placeholder:text-neutral-400 focus:border-neutral-500 focus:ring-1 focus:ring-neutral-500 focus:outline-none dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-100';

function FieldError({ id, message }: { id: string; message?: string }) {
    if (!message) {
        return null;
    }

    return (
        <p
            id={id}
            role="alert"
            className="mt-1 text-sm text-red-600 dark:text-red-400"
        >
            {message}
        </p>
    );
}

export default function JobsCreate() {
    const form = useForm({
        company: '',
        title: '',
        location: '',
        source_url: '',
        description: '',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        form.post(store.url());
    };

    return (
        <AppShell>
            <Head title="Add Job" />

            <Link
                href={jobsIndex.url()}
                className="text-sm font-medium text-neutral-500 hover:text-neutral-700 dark:text-neutral-400 dark:hover:text-neutral-200"
            >
                ← Back to Jobs
            </Link>

            <h1 className="mt-4 text-xl font-semibold text-neutral-900 dark:text-neutral-100">
                Add Job
            </h1>
            <p className="mt-1 text-sm text-neutral-500 dark:text-neutral-400">
                Paste the source job posting as-is. The description is kept
                verbatim — nothing here rewrites it.
            </p>

            <form onSubmit={submit} className="mt-6 max-w-2xl space-y-5">
                <div className="grid grid-cols-1 gap-5 sm:grid-cols-2">
                    <div>
                        <label
                            htmlFor="company"
                            className="block text-sm font-medium text-neutral-700 dark:text-neutral-300"
                        >
                            Company
                        </label>
                        <input
                            id="company"
                            type="text"
                            required
                            value={form.data.company}
                            onChange={(e) =>
                                form.setData('company', e.target.value)
                            }
                            aria-invalid={
                                form.errors.company ? true : undefined
                            }
                            aria-describedby={
                                form.errors.company
                                    ? 'company-error'
                                    : undefined
                            }
                            className={inputClass}
                        />
                        <FieldError
                            id="company-error"
                            message={form.errors.company}
                        />
                    </div>

                    <div>
                        <label
                            htmlFor="title"
                            className="block text-sm font-medium text-neutral-700 dark:text-neutral-300"
                        >
                            Job title
                        </label>
                        <input
                            id="title"
                            type="text"
                            required
                            value={form.data.title}
                            onChange={(e) =>
                                form.setData('title', e.target.value)
                            }
                            aria-invalid={form.errors.title ? true : undefined}
                            aria-describedby={
                                form.errors.title ? 'title-error' : undefined
                            }
                            className={inputClass}
                        />
                        <FieldError
                            id="title-error"
                            message={form.errors.title}
                        />
                    </div>

                    <div>
                        <label
                            htmlFor="location"
                            className="block text-sm font-medium text-neutral-700 dark:text-neutral-300"
                        >
                            Location{' '}
                            <span className="font-normal text-neutral-400">
                                (optional)
                            </span>
                        </label>
                        <input
                            id="location"
                            type="text"
                            value={form.data.location}
                            onChange={(e) =>
                                form.setData('location', e.target.value)
                            }
                            aria-invalid={
                                form.errors.location ? true : undefined
                            }
                            aria-describedby={
                                form.errors.location
                                    ? 'location-error'
                                    : undefined
                            }
                            className={inputClass}
                        />
                        <FieldError
                            id="location-error"
                            message={form.errors.location}
                        />
                    </div>

                    <div>
                        <label
                            htmlFor="source_url"
                            className="block text-sm font-medium text-neutral-700 dark:text-neutral-300"
                        >
                            Source URL{' '}
                            <span className="font-normal text-neutral-400">
                                (optional)
                            </span>
                        </label>
                        <input
                            id="source_url"
                            type="url"
                            placeholder="https://…"
                            value={form.data.source_url}
                            onChange={(e) =>
                                form.setData('source_url', e.target.value)
                            }
                            aria-invalid={
                                form.errors.source_url ? true : undefined
                            }
                            aria-describedby={
                                form.errors.source_url
                                    ? 'source_url-error'
                                    : undefined
                            }
                            className={inputClass}
                        />
                        <FieldError
                            id="source_url-error"
                            message={form.errors.source_url}
                        />
                    </div>
                </div>

                <div>
                    <label
                        htmlFor="description"
                        className="block text-sm font-medium text-neutral-700 dark:text-neutral-300"
                    >
                        Job description
                    </label>
                    <textarea
                        id="description"
                        required
                        rows={16}
                        value={form.data.description}
                        onChange={(e) =>
                            form.setData('description', e.target.value)
                        }
                        aria-invalid={
                            form.errors.description ? true : undefined
                        }
                        aria-describedby={
                            form.errors.description
                                ? 'description-error'
                                : undefined
                        }
                        className={`${inputClass} font-mono text-xs`}
                    />
                    <FieldError
                        id="description-error"
                        message={form.errors.description}
                    />
                </div>

                <div className="flex items-center gap-3">
                    <button
                        type="submit"
                        disabled={form.processing}
                        className="inline-flex items-center rounded-md bg-neutral-900 px-4 py-2 text-sm font-medium text-white hover:bg-neutral-700 disabled:opacity-50 dark:bg-neutral-100 dark:text-neutral-900 dark:hover:bg-neutral-300"
                    >
                        Save Job
                    </button>
                    <Link
                        href={jobsIndex.url()}
                        className="text-sm font-medium text-neutral-500 hover:text-neutral-700 dark:text-neutral-400 dark:hover:text-neutral-200"
                    >
                        Cancel
                    </Link>
                </div>
            </form>
        </AppShell>
    );
}
