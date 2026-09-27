import { Head, Link, useForm } from '@inertiajs/react';
import { GenerationStatus } from '@/components/generation-status';
import { useGenerationAttemptPolling } from '@/hooks/use-generation-attempt-polling';
import AppShell from '@/layouts/app-shell';
import { show as jobsShow } from '@/routes/jobs';
import { show as analysesShow } from '@/routes/jobs/analyses';
import {
    show as resumeShow,
    store as resumeStore,
} from '@/routes/jobs/analyses/matches/resume';
import type { GenerationAttempt } from '@/types/generation-attempt';
import type {
    CareerFactMatchDetail,
    DiscoveryPreflightCandidate,
    EducationMatchDetail,
    JobMatchFindingDetail,
    JobMatchShowProps,
} from '@/types/job-match';

function GenerateResumeAction({
    jobId,
    analysisId,
    matchId,
    initialAttempt,
}: {
    jobId: number;
    analysisId: number;
    matchId: number;
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
                    form.post(
                        resumeStore.url({
                            jobPosting: jobId,
                            jobAnalysis: analysisId,
                            jobMatch: matchId,
                        }),
                        { showProgress: false },
                    )
                }
                className="inline-flex items-center rounded-md bg-neutral-900 px-4 py-2 text-sm font-medium text-white hover:bg-neutral-700 disabled:opacity-50 dark:bg-neutral-100 dark:text-neutral-900 dark:hover:bg-neutral-300"
            >
                {disabled ? 'Generating…' : 'Generate Resume'}
            </button>
            <GenerationStatus
                active={isActive}
                label="Generating resume"
                queued={attempt?.status === 'queued'}
                queuedLabel="Resume queued…"
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

function DiscoveryPreflightList({
    candidates,
}: {
    candidates: DiscoveryPreflightCandidate[];
}) {
    if (candidates.length === 0) {
        return null;
    }

    return (
        <div className="mt-3 rounded-lg border border-amber-200 bg-amber-50 p-3 dark:border-amber-900 dark:bg-amber-950">
            <p className="text-xs font-medium tracking-wide text-amber-700 uppercase dark:text-amber-300">
                Worth confirming before generating
            </p>
            <ul className="mt-2 space-y-1">
                {candidates.map((candidate) => (
                    <li
                        key={candidate.job_analysis_finding_id}
                        className="text-sm text-amber-800 dark:text-amber-200"
                    >
                        {candidate.prompt}
                    </li>
                ))}
            </ul>
            <p className="mt-2 text-xs text-amber-600 dark:text-amber-400">
                Purely informational — generating now uses your
                documented evidence as-is.
            </p>
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

// Human-facing copy is deliberately explicit here — "No evidence in
// your data" and "Not assessable from career history" are worded to
// never read as a verdict about the candidate. See
// docs/job-match-contract.md.
const COVERAGE_COPY: Record<string, string> = {
    supported: 'Supported',
    partial: 'Partial',
    no_evidence: 'No evidence in your data',
    not_assessable: 'Not assessable from career history',
};

const COVERAGE_STYLES: Record<string, string> = {
    supported:
        'bg-green-50 text-green-700 border-green-200 dark:bg-green-950 dark:text-green-300 dark:border-green-900',
    partial:
        'bg-amber-50 text-amber-700 border-amber-200 dark:bg-amber-950 dark:text-amber-300 dark:border-amber-900',
    no_evidence:
        'bg-neutral-100 text-neutral-600 border-neutral-200 dark:bg-neutral-800 dark:text-neutral-400 dark:border-neutral-700',
    not_assessable:
        'bg-blue-50 text-blue-700 border-blue-200 dark:bg-blue-950 dark:text-blue-300 dark:border-blue-900',
};

function CoverageBadge({ coverage }: { coverage: string }) {
    return (
        <span
            className={`inline-flex items-center rounded-full border px-2.5 py-0.5 text-xs font-medium ${COVERAGE_STYLES[coverage] ?? ''}`}
        >
            {COVERAGE_COPY[coverage] ?? formatLabel(coverage)}
        </span>
    );
}

function formatRoleDates(dates: CareerFactMatchDetail['role_dates']): string | null {
    if (!dates) {
        return null;
    }

    const start = dates.start_month
        ? `${dates.start_month}/${dates.start_year}`
        : `${dates.start_year}`;
    const end = dates.end_year
        ? dates.end_month
            ? `${dates.end_month}/${dates.end_year}`
            : `${dates.end_year}`
        : 'present';

    return `${start} – ${end}`;
}

function CareerFactSupportCard({ match }: { match: CareerFactMatchDetail }) {
    const roleDates = formatRoleDates(match.role_dates);
    const attributionParts = [
        match.attribution.employer,
        match.attribution.role,
        match.attribution.project,
    ].filter(Boolean);

    return (
        <div className="rounded border border-neutral-200 bg-neutral-50 p-3 dark:border-neutral-800 dark:bg-neutral-900/50">
            <div className="flex flex-wrap items-center gap-1.5">
                <Badge label="Relationship" value={match.relationship} />
                <Badge label="Visibility" value={match.visibility} />
            </div>
            <p className="mt-2 text-sm text-neutral-800 dark:text-neutral-200">
                {match.statement}
            </p>
            {(attributionParts.length > 0 || roleDates) && (
                <p className="mt-1 text-xs text-neutral-400 dark:text-neutral-500">
                    {attributionParts.join(' → ')}
                    {roleDates && attributionParts.length > 0 ? ' · ' : ''}
                    {roleDates}
                </p>
            )}
            {match.rationale && (
                <div className="mt-2">
                    <p className="text-[11px] font-medium tracking-wide text-neutral-400 uppercase dark:text-neutral-500">
                        Reasoning
                    </p>
                    <p className="text-xs text-neutral-500 italic dark:text-neutral-400">
                        {match.rationale}
                    </p>
                </div>
            )}
        </div>
    );
}

function EducationSupportCard({ match }: { match: EducationMatchDetail }) {
    return (
        <div className="rounded border border-neutral-200 bg-neutral-50 p-3 dark:border-neutral-800 dark:bg-neutral-900/50">
            <div className="flex flex-wrap items-center gap-1.5">
                <Badge label="Relationship" value={match.relationship} />
                <span className="inline-flex items-center rounded-full border border-neutral-200 px-2 py-0.5 text-xs text-neutral-600 dark:border-neutral-700 dark:text-neutral-300">
                    Education
                </span>
            </div>
            <p className="mt-2 text-sm text-neutral-800 dark:text-neutral-200">
                {match.degree}
                {match.field_of_study ? ` — ${match.field_of_study}` : ''}
            </p>
            <p className="mt-1 text-xs text-neutral-400 dark:text-neutral-500">
                {match.institution}
                {match.end_year ? ` · ${match.end_year}` : ''}
            </p>
            {match.rationale && (
                <div className="mt-2">
                    <p className="text-[11px] font-medium tracking-wide text-neutral-400 uppercase dark:text-neutral-500">
                        Reasoning
                    </p>
                    <p className="text-xs text-neutral-500 italic dark:text-neutral-400">
                        {match.rationale}
                    </p>
                </div>
            )}
        </div>
    );
}

function ExperienceRequirement({
    finding,
}: {
    finding: JobMatchFindingDetail;
}) {
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

    return <Badge label="Employer-stated experience" value={text} />;
}

function FindingResultCard({ finding }: { finding: JobMatchFindingDetail }) {
    return (
        <div className="rounded-lg border border-neutral-200 bg-white p-4 dark:border-neutral-800 dark:bg-neutral-900">
            <div className="flex flex-wrap items-start justify-between gap-2">
                <p className="text-sm font-medium text-neutral-900 dark:text-neutral-100">
                    {finding.statement}
                </p>
                <CoverageBadge coverage={finding.coverage} />
            </div>

            <div className="mt-2 flex flex-wrap gap-1.5">
                <Badge
                    label="Requirement"
                    value={finding.requirement_strength}
                />
                <Badge label="Emphasis" value={finding.emphasis} />
                <Badge label="Maturity" value={finding.maturity} />
                <Badge label="Basis" value={finding.basis} />
                <ExperienceRequirement finding={finding} />
                <Badge
                    label="Recency"
                    value={finding.recency_requirement}
                />
                <Badge label="Time horizon" value={finding.time_horizon} />
            </div>

            {finding.coverage_rationale && (
                <div className="mt-3">
                    <p className="text-[11px] font-medium tracking-wide text-neutral-400 uppercase dark:text-neutral-500">
                        Reasoning
                    </p>
                    <p className="text-xs text-neutral-500 italic dark:text-neutral-400">
                        {finding.coverage_rationale}
                    </p>
                </div>
            )}

            {(finding.career_fact_matches.length > 0 ||
                finding.education_matches.length > 0) && (
                <div className="mt-3 space-y-2">
                    {finding.career_fact_matches.map((match, index) => (
                        <CareerFactSupportCard key={index} match={match} />
                    ))}
                    {finding.education_matches.map((match, index) => (
                        <EducationSupportCard key={index} match={match} />
                    ))}
                </div>
            )}
        </div>
    );
}

export default function JobMatchesShow({
    job,
    analysis,
    match,
}: JobMatchShowProps) {
    return (
        <AppShell>
            <Head title={`Match — ${job.title} at ${job.company}`} />

            <Link
                href={analysesShow.url({
                    jobPosting: job.id,
                    jobAnalysis: analysis.id,
                })}
                className="text-sm font-medium text-neutral-500 hover:text-neutral-700 dark:text-neutral-400 dark:hover:text-neutral-200"
            >
                ← Back to analysis
            </Link>

            <div className="mt-4">
                <h1 className="text-xl font-semibold text-neutral-900 dark:text-neutral-100">
                    Candidate Match
                </h1>
                <p className="text-sm text-neutral-600 dark:text-neutral-400">
                    {job.title} at {job.company}
                </p>
                <dl className="mt-3 flex flex-wrap gap-x-6 gap-y-1 text-xs text-neutral-500 dark:text-neutral-400">
                    <div className="flex gap-1">
                        <dt className="font-medium text-neutral-400 dark:text-neutral-500">
                            Generated:
                        </dt>
                        <dd>{match.generated_at ?? '—'}</dd>
                    </div>
                    <div className="flex gap-1">
                        <dt className="font-medium text-neutral-400 dark:text-neutral-500">
                            Provider/model:
                        </dt>
                        <dd>{match.generated_by ?? '—'}</dd>
                    </div>
                    <div className="flex gap-1">
                        <dt className="font-medium text-neutral-400 dark:text-neutral-500">
                            Prompt:
                        </dt>
                        <dd>{match.prompt_version ?? '—'}</dd>
                    </div>
                    <div className="flex gap-1">
                        <dt className="font-medium text-neutral-400 dark:text-neutral-500">
                            Schema:
                        </dt>
                        <dd>{match.schema_version}</dd>
                    </div>
                </dl>
            </div>

            <div className="mt-6">
                <div className="flex items-center justify-between">
                    <h2 className="text-sm font-semibold text-neutral-900 dark:text-neutral-100">
                        Tailored Resume
                    </h2>
                    <GenerateResumeAction
                        jobId={job.id}
                        analysisId={analysis.id}
                        matchId={match.id}
                        initialAttempt={match.latest_resume_attempt}
                    />
                </div>

                <DiscoveryPreflightList
                    candidates={match.discovery_preflight}
                />

                {match.resume_variants.length === 0 ? (
                    <p className="mt-2 text-xs text-neutral-400 dark:text-neutral-500">
                        No resume has been generated yet.
                    </p>
                ) : (
                    <ul className="mt-2 divide-y divide-neutral-200 rounded-lg border border-neutral-200 bg-white dark:divide-neutral-800 dark:border-neutral-800 dark:bg-neutral-900">
                        {match.resume_variants.map((variant) => (
                            <li key={variant.id}>
                                <Link
                                    href={resumeShow.url({
                                        jobPosting: job.id,
                                        jobAnalysis: analysis.id,
                                        jobMatch: match.id,
                                        resumeVariant: variant.id,
                                    })}
                                    className="flex items-center justify-between px-4 py-3 text-sm hover:bg-neutral-50 dark:hover:bg-neutral-800/50"
                                >
                                    <span className="text-neutral-700 dark:text-neutral-300">
                                        {variant.generated_at ?? '—'}
                                    </span>
                                </Link>
                            </li>
                        ))}
                    </ul>
                )}
            </div>

            <div className="mt-6 space-y-8">
                {match.categories.map((group) => (
                    <div key={group.category}>
                        <h2 className="text-sm font-semibold text-neutral-900 dark:text-neutral-100">
                            {formatLabel(group.category)}
                        </h2>
                        <div className="mt-2 space-y-3">
                            {group.findings.map((finding) => (
                                <FindingResultCard
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
