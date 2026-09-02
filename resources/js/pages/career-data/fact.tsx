import { Head, Link } from '@inertiajs/react';
import { VerificationBadge, VisibilityBadge } from '@/components/badges';
import EvidenceList from '@/components/career-data/evidence-list';
import MetricDisplay from '@/components/career-data/metric-display';
import AppShell from '@/layouts/app-shell';
import { index as careerDataIndex } from '@/routes/career-data';
import type { CareerDataFactProps } from '@/types/career-data';

export default function CareerDataFact({ fact }: CareerDataFactProps) {
    return (
        <AppShell>
            <Head title={fact.statement} />

            <Link
                href={careerDataIndex.url()}
                className="text-sm font-medium text-neutral-500 hover:text-neutral-700 dark:text-neutral-400 dark:hover:text-neutral-200"
            >
                ← Back to Career Data
            </Link>

            <div className="mt-4 space-y-6">
                <div>
                    <p className="text-xs text-neutral-400 dark:text-neutral-500">
                        {fact.attribution.path.join(' → ')}
                    </p>
                    <h1 className="mt-1 text-xl font-semibold text-neutral-900 dark:text-neutral-100">
                        {fact.statement}
                    </h1>

                    <div className="mt-3 flex flex-wrap items-center gap-2">
                        <VerificationBadge value={fact.verification} />
                        <VisibilityBadge value={fact.visibility} />
                        <span className="inline-flex items-center rounded-md bg-neutral-100 px-2 py-0.5 text-xs font-medium text-neutral-600 dark:bg-neutral-800 dark:text-neutral-400">
                            {fact.fact_type}
                        </span>
                    </div>

                    <p className="mt-2 font-mono text-xs text-neutral-400 dark:text-neutral-500">
                        {fact.key}
                    </p>
                </div>

                {fact.notes && (
                    <div>
                        <h2 className="text-sm font-semibold text-neutral-900 dark:text-neutral-100">
                            Notes
                        </h2>
                        <p className="mt-1.5 text-sm text-neutral-600 dark:text-neutral-400">
                            {fact.notes}
                        </p>
                    </div>
                )}

                {fact.metric && (
                    <div>
                        <h2 className="text-sm font-semibold text-neutral-900 dark:text-neutral-100">
                            Metric
                        </h2>
                        <div className="mt-1.5">
                            <MetricDisplay metric={fact.metric} />
                        </div>
                    </div>
                )}

                {fact.skills.length > 0 && (
                    <div>
                        <h2 className="text-sm font-semibold text-neutral-900 dark:text-neutral-100">
                            Skills
                        </h2>
                        <div className="mt-1.5 flex flex-wrap gap-1.5">
                            {fact.skills.map((skill) => (
                                <span
                                    key={skill.slug}
                                    className="rounded bg-neutral-100 px-2 py-0.5 text-xs text-neutral-600 dark:bg-neutral-800 dark:text-neutral-300"
                                >
                                    {skill.name}
                                </span>
                            ))}
                        </div>
                    </div>
                )}

                <div>
                    <h2 className="text-sm font-semibold text-neutral-900 dark:text-neutral-100">
                        Evidence
                    </h2>
                    <div className="mt-1.5">
                        <EvidenceList evidence={fact.evidence} />
                    </div>
                </div>
            </div>
        </AppShell>
    );
}
