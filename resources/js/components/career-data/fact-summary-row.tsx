import { Link } from '@inertiajs/react';
import { VerificationBadge, VisibilityBadge } from '@/components/badges';
import { show as showFact } from '@/routes/career-data/facts';
import type { FactSummary } from '@/types/career-data';

export default function FactSummaryRow({ fact }: { fact: FactSummary }) {
    return (
        <Link
            href={showFact.url(fact.key)}
            className="flex flex-col gap-1.5 rounded-md border border-neutral-200 p-3 text-sm hover:border-neutral-300 hover:bg-neutral-50 dark:border-neutral-800 dark:hover:border-neutral-700 dark:hover:bg-neutral-800/50"
        >
            <p className="text-neutral-800 dark:text-neutral-200">
                {fact.statement}
            </p>
            <div className="flex flex-wrap items-center gap-1.5">
                <VerificationBadge value={fact.verification} />
                <VisibilityBadge value={fact.visibility} />
                {fact.has_metric && (
                    <span className="inline-flex items-center rounded-md bg-indigo-50 px-2 py-0.5 text-xs font-medium text-indigo-700 ring-1 ring-indigo-600/20 ring-inset dark:bg-indigo-500/10 dark:text-indigo-300 dark:ring-indigo-400/20">
                        Metric
                    </span>
                )}
            </div>
        </Link>
    );
}
