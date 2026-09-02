import type { EvidenceDetail } from '@/types/career-data';

const SOURCE_LABELS: Record<string, string> = {
    resume: 'Resume',
    portfolio: 'Portfolio',
    repository: 'Repository',
    user_confirmed: 'User confirmed',
};

function EvidenceRow({ evidence }: { evidence: EvidenceDetail }) {
    const locatorParts = [
        evidence.document,
        evidence.section,
        evidence.locator,
    ].filter(Boolean);

    return (
        <li className="rounded-md border border-neutral-200 p-3 text-sm dark:border-neutral-800">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <span className="inline-flex items-center rounded-md bg-neutral-100 px-2 py-0.5 text-xs font-medium text-neutral-700 dark:bg-neutral-800 dark:text-neutral-300">
                    {SOURCE_LABELS[evidence.source] ?? evidence.source}
                </span>
                {evidence.confirmed_at && (
                    <span className="text-xs text-neutral-400 dark:text-neutral-500">
                        Confirmed {evidence.confirmed_at}
                    </span>
                )}
            </div>

            {locatorParts.length > 0 && (
                <p className="mt-1.5 text-xs text-neutral-500 dark:text-neutral-400">
                    {locatorParts.join(' · ')}
                </p>
            )}

            {evidence.path && (
                <p className="mt-1 font-mono text-xs break-all text-neutral-500 dark:text-neutral-400">
                    {evidence.path}
                </p>
            )}

            {evidence.quoted_text && (
                <blockquote className="mt-2 border-l-2 border-neutral-300 pl-3 text-sm text-neutral-700 italic dark:border-neutral-700 dark:text-neutral-300">
                    “{evidence.quoted_text}”
                </blockquote>
            )}

            {evidence.note && (
                <p className="mt-2 text-sm text-neutral-600 dark:text-neutral-400">
                    {evidence.note}
                </p>
            )}
        </li>
    );
}

export default function EvidenceList({
    evidence,
}: {
    evidence: EvidenceDetail[];
}) {
    if (evidence.length === 0) {
        return (
            <p className="text-sm text-neutral-400 italic dark:text-neutral-600">
                No evidence recorded for this fact.
            </p>
        );
    }

    return (
        <ul className="space-y-2">
            {evidence.map((item, index) => (
                // Evidence has no stable id of its own in the domain model
                // (see docs/domain-model.md) — position is stable for a
                // given page render.
                <EvidenceRow key={index} evidence={item} />
            ))}
        </ul>
    );
}
