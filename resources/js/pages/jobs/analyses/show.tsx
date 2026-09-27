import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { GenerationStatus } from '@/components/generation-status';
import AppShell from '@/layouts/app-shell';
import { show as jobsShow } from '@/routes/jobs';
import {
    show as matchesShow,
    store as matchesStore,
} from '@/routes/jobs/analyses/matches';
import type {
    JobAnalysisFinding,
    JobAnalysisShowProps,
} from '@/types/job-analysis';

type PageErrors = { errors?: { match_generation?: string } };

function GenerateMatchAction({
    jobId,
    analysisId,
}: {
    jobId: number;
    analysisId: number;
}) {
    const form = useForm({});
    const { errors } = usePage<PageErrors>().props;

    return (
        <div>
            <button
                type="button"
                disabled={form.processing}
                onClick={() =>
                    form.post(
                        matchesStore.url({
                            jobPosting: jobId,
                            jobAnalysis: analysisId,
                        }),
                        { showProgress: false },
                    )
                }
                className="inline-flex items-center rounded-md bg-neutral-900 px-4 py-2 text-sm font-medium text-white hover:bg-neutral-700 disabled:opacity-50 dark:bg-neutral-100 dark:text-neutral-900 dark:hover:bg-neutral-300"
            >
                {form.processing
                    ? 'Matching…'
                    : 'Match Against My Profile'}
            </button>
            <GenerationStatus
                active={form.processing}
                label="Matching against your profile"
            />
            {errors?.match_generation && (
                <p
                    role="alert"
                    className="mt-2 text-sm text-red-600 dark:text-red-400"
                >
                    {errors.match_generation}
                </p>
            )}
        </div>
    );
}

function formatLabel(value: string | null): string | null {
    if (!value) {
        return null;
    }

    return value
        .split('_')
        .map((word) => word.charAt(0).toUpperCase() + word.slice(1))
        .join(' ');
}

function Badge({
    label,
    value,
}: {
    label: string;
    value: string | null;
}) {
    if (!value) {
        return null;
    }

    return (
        <span className="inline-flex items-center gap-1 rounded-full border border-neutral-200 px-2 py-0.5 text-xs text-neutral-600 dark:border-neutral-700 dark:text-neutral-300">
            <span className="text-neutral-400 dark:text-neutral-500">
                {label}:
            </span>
            {formatLabel(value)}
        </span>
    );
}

function ExperienceRange({ finding }: { finding: JobAnalysisFinding }) {
    const { years_experience_min: min, years_experience_max: max } = finding;

    if (min === null && max === null) {
        return null;
    }

    const text =
        min !== null && max !== null
            ? `${min}–${max} years`
            : min !== null
              ? `${min}+ years`
              : `up to ${max} years`;

    return <Badge label="Experience" value={text} />;
}

function FindingCard({ finding }: { finding: JobAnalysisFinding }) {
    return (
        <div className="rounded-lg border border-neutral-200 bg-white p-4 dark:border-neutral-800 dark:bg-neutral-900">
            <div className="flex flex-wrap items-start justify-between gap-2">
                <p className="text-sm font-medium text-neutral-900 dark:text-neutral-100">
                    {finding.statement}
                </p>
                {finding.label && (
                    <span className="shrink-0 rounded bg-neutral-100 px-1.5 py-0.5 text-[11px] font-mono text-neutral-500 dark:bg-neutral-800 dark:text-neutral-400">
                        {finding.label}
                    </span>
                )}
            </div>

            <div className="mt-2 flex flex-wrap gap-1.5">
                <Badge label="Basis" value={finding.basis} />
                <Badge
                    label="Requirement"
                    value={finding.requirement_strength}
                />
                <Badge label="Emphasis" value={finding.emphasis} />
                <Badge label="Maturity" value={finding.maturity} />
                <ExperienceRange finding={finding} />
                <Badge
                    label="Recency"
                    value={finding.recency_requirement}
                />
                <Badge label="Time horizon" value={finding.time_horizon} />
            </div>

            {finding.notes && (
                <p className="mt-2 text-xs text-neutral-500 italic dark:text-neutral-400">
                    {finding.notes}
                </p>
            )}

            <div className="mt-3 space-y-2">
                {finding.evidence.map((evidence, index) => (
                    <blockquote
                        key={index}
                        className="border-l-2 border-neutral-200 pl-3 text-xs text-neutral-600 dark:border-neutral-700 dark:text-neutral-400"
                    >
                        <p className="italic">&ldquo;{evidence.excerpt}&rdquo;</p>
                        {(evidence.source_section ||
                            evidence.source_locator) && (
                            <p className="mt-0.5 text-[11px] text-neutral-400 dark:text-neutral-500">
                                {[
                                    evidence.source_section,
                                    evidence.source_locator,
                                ]
                                    .filter(Boolean)
                                    .join(' · ')}
                            </p>
                        )}
                    </blockquote>
                ))}
            </div>
        </div>
    );
}

export default function JobAnalysesShow({
    job,
    analysis,
}: JobAnalysisShowProps) {
    return (
        <AppShell>
            <Head title={`Analysis — ${job.title} at ${job.company}`} />

            <Link
                href={jobsShow.url({ jobPosting: job.id })}
                className="text-sm font-medium text-neutral-500 hover:text-neutral-700 dark:text-neutral-400 dark:hover:text-neutral-200"
            >
                ← Back to {job.title} at {job.company}
            </Link>

            <div className="mt-4">
                <h1 className="text-xl font-semibold text-neutral-900 dark:text-neutral-100">
                    Job Analysis
                </h1>
                <dl className="mt-3 flex flex-wrap gap-x-6 gap-y-1 text-xs text-neutral-500 dark:text-neutral-400">
                    <div className="flex gap-1">
                        <dt className="font-medium text-neutral-400 dark:text-neutral-500">
                            Generated:
                        </dt>
                        <dd>{analysis.generated_at ?? '—'}</dd>
                    </div>
                    <div className="flex gap-1">
                        <dt className="font-medium text-neutral-400 dark:text-neutral-500">
                            Provider/model:
                        </dt>
                        <dd>{analysis.generated_by ?? '—'}</dd>
                    </div>
                    <div className="flex gap-1">
                        <dt className="font-medium text-neutral-400 dark:text-neutral-500">
                            Prompt:
                        </dt>
                        <dd>{analysis.prompt_version ?? '—'}</dd>
                    </div>
                    <div className="flex gap-1">
                        <dt className="font-medium text-neutral-400 dark:text-neutral-500">
                            Schema:
                        </dt>
                        <dd>{analysis.schema_version}</dd>
                    </div>
                </dl>
            </div>

            <div className="mt-6 rounded-lg border border-neutral-200 bg-white p-4 dark:border-neutral-800 dark:bg-neutral-900">
                <h2 className="text-sm font-semibold text-neutral-900 dark:text-neutral-100">
                    Role Summary
                </h2>
                <p className="mt-1 text-sm leading-relaxed text-neutral-700 dark:text-neutral-300">
                    {analysis.role_summary}
                </p>

                <h2 className="mt-4 text-sm font-semibold text-neutral-900 dark:text-neutral-100">
                    Overall Seniority
                </h2>
                <p className="mt-1 text-sm text-neutral-700 dark:text-neutral-300">
                    {formatLabel(analysis.overall_seniority) ?? '—'}
                    {analysis.seniority_rationale && (
                        <span className="text-neutral-500 dark:text-neutral-400">
                            {' '}
                            — {analysis.seniority_rationale}
                        </span>
                    )}
                </p>
            </div>

            <div className="mt-6">
                <div className="flex items-center justify-between">
                    <h2 className="text-sm font-semibold text-neutral-900 dark:text-neutral-100">
                        Candidate Match
                    </h2>
                    <GenerateMatchAction
                        jobId={job.id}
                        analysisId={analysis.id}
                    />
                </div>

                {analysis.matches.length === 0 ? (
                    <p className="mt-2 text-xs text-neutral-400 dark:text-neutral-500">
                        No match has been generated yet.
                    </p>
                ) : (
                    <ul className="mt-2 divide-y divide-neutral-200 rounded-lg border border-neutral-200 bg-white dark:divide-neutral-800 dark:border-neutral-800 dark:bg-neutral-900">
                        {analysis.matches.map((match) => (
                            <li key={match.id}>
                                <Link
                                    href={matchesShow.url({
                                        jobPosting: job.id,
                                        jobAnalysis: analysis.id,
                                        jobMatch: match.id,
                                    })}
                                    className="flex items-center justify-between px-4 py-3 text-sm hover:bg-neutral-50 dark:hover:bg-neutral-800/50"
                                >
                                    <span className="text-neutral-700 dark:text-neutral-300">
                                        {match.generated_at ?? '—'}
                                    </span>
                                    <span className="text-xs text-neutral-400 dark:text-neutral-500">
                                        {match.findings_count ?? 0}{' '}
                                        finding
                                        {match.findings_count === 1
                                            ? ''
                                            : 's'}{' '}
                                        addressed
                                    </span>
                                </Link>
                            </li>
                        ))}
                    </ul>
                )}
            </div>

            <div className="mt-6 space-y-8">
                {analysis.categories.map((group) => (
                    <div key={group.category}>
                        <h2 className="text-sm font-semibold text-neutral-900 dark:text-neutral-100">
                            {formatLabel(group.category)}
                        </h2>
                        <div className="mt-2 space-y-3">
                            {group.findings.map((finding) => (
                                <FindingCard
                                    key={finding.id}
                                    finding={finding}
                                />
                            ))}
                        </div>
                    </div>
                ))}
            </div>
        </AppShell>
    );
}
