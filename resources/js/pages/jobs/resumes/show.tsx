import { Head, Link } from '@inertiajs/react';
import AppShell from '@/layouts/app-shell';
import { show as matchesShow } from '@/routes/jobs/analyses/matches';
import type {
    ResumeBullet,
    ResumeCareerFactSummary,
    ResumeRoleGroup,
    ResumeTargetTermUsage,
    ResumeVariantShowProps,
} from '@/types/resume-variant';

function formatLabel(value: string): string {
    return value
        .split('_')
        .map((word) => word.charAt(0).toUpperCase() + word.slice(1))
        .join(' ');
}

const POSTURE_COPY: Record<string, string> = {
    direct: 'Direct experience',
    qualified: 'Qualified/adjacent',
    capability: 'Underlying capability',
};

const POSTURE_STYLES: Record<string, string> = {
    direct: 'bg-green-50 text-green-700 border-green-200 dark:bg-green-950 dark:text-green-300 dark:border-green-900',
    qualified:
        'bg-amber-50 text-amber-700 border-amber-200 dark:bg-amber-950 dark:text-amber-300 dark:border-amber-900',
    capability:
        'bg-blue-50 text-blue-700 border-blue-200 dark:bg-blue-950 dark:text-blue-300 dark:border-blue-900',
};

function PostureBadge({ posture }: { posture: string }) {
    return (
        <span
            className={`inline-flex items-center rounded-full border px-2.5 py-0.5 text-xs font-medium ${POSTURE_STYLES[posture] ?? ''}`}
        >
            {POSTURE_COPY[posture] ?? formatLabel(posture)}
        </span>
    );
}

function CareerFactChip({ fact }: { fact: ResumeCareerFactSummary }) {
    const attributionParts = [
        fact.attribution.employer,
        fact.attribution.role,
        fact.attribution.project,
    ].filter(Boolean);

    return (
        <div className="rounded border border-neutral-200 bg-neutral-50 p-2 text-xs dark:border-neutral-800 dark:bg-neutral-900/50">
            <p className="text-neutral-700 dark:text-neutral-300">
                {fact.statement}
            </p>
            <p className="mt-1 text-neutral-400 dark:text-neutral-500">
                {attributionParts.join(' → ')}
                {attributionParts.length > 0 ? ' · ' : ''}
                {fact.visibility}
            </p>
        </div>
    );
}

function TargetTermUsageCard({ usage }: { usage: ResumeTargetTermUsage }) {
    return (
        <div className="rounded border border-neutral-200 bg-white p-2 dark:border-neutral-800 dark:bg-neutral-900">
            <div className="flex flex-wrap items-center gap-1.5">
                <span className="text-xs font-medium text-neutral-800 dark:text-neutral-200">
                    {usage.term}
                </span>
                <PostureBadge posture={usage.posture} />
                {usage.relationship_phrase && (
                    <span className="text-xs text-neutral-400 dark:text-neutral-500">
                        &ldquo;{usage.relationship_phrase}&rdquo;
                    </span>
                )}
            </div>
            <p className="mt-1 text-xs text-neutral-500 dark:text-neutral-400">
                Addresses: {usage.job_analysis_finding_statement}
            </p>
            <div className="mt-1 space-y-1">
                {usage.evidence.map((fact) => (
                    <CareerFactChip key={fact.key} fact={fact} />
                ))}
            </div>
        </div>
    );
}

function BulletCard({ bullet }: { bullet: ResumeBullet }) {
    return (
        <li className="rounded-lg border border-neutral-200 bg-white p-4 dark:border-neutral-800 dark:bg-neutral-900">
            <p className="text-sm text-neutral-900 dark:text-neutral-100">
                {bullet.text}
            </p>
            {bullet.project && (
                <p className="mt-1 text-xs text-neutral-400 dark:text-neutral-500">
                    Project: {bullet.project}
                </p>
            )}
            <div className="mt-2">
                <p className="text-[11px] font-medium tracking-wide text-neutral-400 uppercase dark:text-neutral-500">
                    Source CareerFacts
                </p>
                <div className="mt-1 space-y-1">
                    {bullet.citations.map((fact) => (
                        <CareerFactChip key={fact.key} fact={fact} />
                    ))}
                </div>
            </div>
            {bullet.target_term_usages.length > 0 && (
                <div className="mt-2 space-y-1">
                    {bullet.target_term_usages.map((usage) => (
                        <TargetTermUsageCard
                            key={usage.term}
                            usage={usage}
                        />
                    ))}
                </div>
            )}
        </li>
    );
}

function RoleSection({ role }: { role: ResumeRoleGroup }) {
    const dateRange = `${role.start_month ?? ''}${role.start_month ? '/' : ''}${role.start_year} – ${
        role.end_year
            ? `${role.end_month ? role.end_month + '/' : ''}${role.end_year}`
            : 'present'
    }`;

    return (
        <div>
            <div className="flex flex-wrap items-baseline justify-between gap-1">
                <h3 className="text-sm font-semibold text-neutral-900 dark:text-neutral-100">
                    {role.display_title} — {role.employer}
                </h3>
                <span className="text-xs text-neutral-400 dark:text-neutral-500">
                    {dateRange}
                </span>
            </div>
            <ul className="mt-2 space-y-2">
                {role.bullets.map((bullet) => (
                    <BulletCard key={bullet.id} bullet={bullet} />
                ))}
            </ul>
        </div>
    );
}

export default function ResumeVariantShow({
    job,
    analysis,
    match,
    resume,
}: ResumeVariantShowProps) {
    return (
        <AppShell>
            <Head title={`Resume — ${job.title} at ${job.company}`} />

            <Link
                href={matchesShow.url({
                    jobPosting: job.id,
                    jobAnalysis: analysis.id,
                    jobMatch: match.id,
                })}
                className="text-sm font-medium text-neutral-500 hover:text-neutral-700 dark:text-neutral-400 dark:hover:text-neutral-200"
            >
                ← Back to match
            </Link>

            <div className="mt-4">
                <h1 className="text-xl font-semibold text-neutral-900 dark:text-neutral-100">
                    Tailored Resume
                </h1>
                <p className="text-sm text-neutral-600 dark:text-neutral-400">
                    {job.title} at {job.company}
                </p>
                <dl className="mt-3 flex flex-wrap gap-x-6 gap-y-1 text-xs text-neutral-500 dark:text-neutral-400">
                    <div className="flex gap-1">
                        <dt className="font-medium text-neutral-400 dark:text-neutral-500">
                            Generated:
                        </dt>
                        <dd>{resume.generated_at ?? '—'}</dd>
                    </div>
                    <div className="flex gap-1">
                        <dt className="font-medium text-neutral-400 dark:text-neutral-500">
                            Selection model:
                        </dt>
                        <dd>{resume.selection_generated_by ?? '—'}</dd>
                    </div>
                    <div className="flex gap-1">
                        <dt className="font-medium text-neutral-400 dark:text-neutral-500">
                            Wording model:
                        </dt>
                        <dd>{resume.wording_generated_by ?? '—'}</dd>
                    </div>
                    <div className="flex gap-1">
                        <dt className="font-medium text-neutral-400 dark:text-neutral-500">
                            Schema:
                        </dt>
                        <dd>{resume.schema_version}</dd>
                    </div>
                </dl>
            </div>

            <div className="mt-6">
                <h2 className="text-sm font-semibold text-neutral-900 dark:text-neutral-100">
                    Summary
                </h2>
                <p className="mt-2 text-sm text-neutral-800 dark:text-neutral-200">
                    {resume.summary}
                </p>
                {resume.summary_evidence.length > 0 && (
                    <div className="mt-2 space-y-1">
                        {resume.summary_evidence.map((fact) => (
                            <CareerFactChip key={fact.key} fact={fact} />
                        ))}
                    </div>
                )}
                {resume.summary_target_term_usages.length > 0 && (
                    <div className="mt-2 space-y-1">
                        {resume.summary_target_term_usages.map((usage) => (
                            <TargetTermUsageCard
                                key={usage.term}
                                usage={usage}
                            />
                        ))}
                    </div>
                )}
            </div>

            <div className="mt-8 space-y-8">
                <div>
                    <h2 className="text-sm font-semibold text-neutral-900 dark:text-neutral-100">
                        Experience
                    </h2>
                    <div className="mt-3 space-y-6">
                        {resume.experience.map((role) => (
                            <RoleSection key={role.role_id} role={role} />
                        ))}
                    </div>
                </div>

                <div>
                    <h2 className="text-sm font-semibold text-neutral-900 dark:text-neutral-100">
                        Skills
                    </h2>
                    <div className="mt-2 flex flex-wrap gap-1.5">
                        {resume.skills.map((skill) => (
                            <span
                                key={skill.id}
                                className="inline-flex items-center rounded-full border border-neutral-200 px-2.5 py-0.5 text-xs text-neutral-700 dark:border-neutral-700 dark:text-neutral-300"
                            >
                                {skill.name}
                            </span>
                        ))}
                    </div>
                </div>

                <div>
                    <h2 className="text-sm font-semibold text-neutral-900 dark:text-neutral-100">
                        Education
                    </h2>
                    <ul className="mt-2 space-y-2">
                        {resume.education.map((entry, index) => (
                            <li
                                key={index}
                                className="rounded-lg border border-neutral-200 bg-white p-3 text-sm dark:border-neutral-800 dark:bg-neutral-900"
                            >
                                <p className="text-neutral-900 dark:text-neutral-100">
                                    {entry.degree}
                                    {entry.field_of_study
                                        ? ` — ${entry.field_of_study}`
                                        : ''}
                                </p>
                                <p className="mt-1 text-xs text-neutral-400 dark:text-neutral-500">
                                    {entry.institution}
                                    {entry.end_year
                                        ? ` · ${entry.end_year}`
                                        : ''}
                                </p>
                            </li>
                        ))}
                    </ul>
                </div>
            </div>
        </AppShell>
    );
}
