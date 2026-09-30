import type { InspectionSummary } from '@/types/application-inspection';
import { cn } from '@/lib/utils';
import type {
    SkillCategory,
    Verification,
    Visibility,
} from '@/types/career-data';

const badgeClass =
    'inline-flex items-center rounded-md px-2 py-0.5 text-xs font-medium ring-1 ring-inset whitespace-nowrap';

const VERIFICATION_STYLES: Record<Verification, string> = {
    verified:
        'bg-emerald-50 text-emerald-700 ring-emerald-600/20 dark:bg-emerald-500/10 dark:text-emerald-300 dark:ring-emerald-400/20',
    strongly_supported:
        'bg-sky-50 text-sky-700 ring-sky-600/20 dark:bg-sky-500/10 dark:text-sky-300 dark:ring-sky-400/20',
    needs_confirmation:
        'bg-amber-50 text-amber-700 ring-amber-600/20 dark:bg-amber-500/10 dark:text-amber-300 dark:ring-amber-400/20',
};

const VERIFICATION_LABELS: Record<Verification, string> = {
    verified: 'Verified',
    strongly_supported: 'Strongly supported',
    needs_confirmation: 'Needs confirmation',
};

/** Trust dimension — deliberately styled with no relation to VisibilityBadge, so the two are never confused for one another. */
export function VerificationBadge({ value }: { value: Verification }) {
    return (
        <span className={cn(badgeClass, VERIFICATION_STYLES[value])}>
            {VERIFICATION_LABELS[value]}
        </span>
    );
}

const VISIBILITY_STYLES: Record<Visibility, string> = {
    public: 'bg-neutral-100 text-neutral-700 ring-neutral-500/20 dark:bg-neutral-800 dark:text-neutral-300 dark:ring-neutral-400/10',
    restricted:
        'bg-orange-50 text-orange-700 ring-orange-600/20 dark:bg-orange-500/10 dark:text-orange-300 dark:ring-orange-400/20',
    private:
        'bg-rose-50 text-rose-700 ring-rose-600/20 dark:bg-rose-500/10 dark:text-rose-300 dark:ring-rose-400/20',
};

const VISIBILITY_LABELS: Record<Visibility, string> = {
    public: 'Public',
    restricted: 'Restricted',
    private: 'Private',
};

/** Confidentiality dimension — a fact can be Verified AND Restricted at once; nothing here implies the other. */
export function VisibilityBadge({ value }: { value: Visibility }) {
    return (
        <span className={cn(badgeClass, VISIBILITY_STYLES[value])}>
            {VISIBILITY_LABELS[value]}
        </span>
    );
}

const CATEGORY_LABELS: Record<SkillCategory, string> = {
    build_technology: 'Build technology',
    platform_integration: 'Platform integration',
    capability: 'Capability',
    practice: 'Practice',
};

export function skillCategoryLabel(value: SkillCategory): string {
    return CATEGORY_LABELS[value];
}

export function SkillCategoryBadge({ value }: { value: SkillCategory }) {
    return (
        <span
            className={cn(
                badgeClass,
                'bg-violet-50 text-violet-700 ring-violet-600/20 dark:bg-violet-500/10 dark:text-violet-300 dark:ring-violet-400/20',
            )}
        >
            {CATEGORY_LABELS[value]}
        </span>
    );
}

const INSPECTION_STATE_STYLES: Record<InspectionSummary['state'], string> = {
    not_inspected:
        'bg-neutral-100 text-neutral-500 ring-neutral-500/20 dark:bg-neutral-800 dark:text-neutral-400 dark:ring-neutral-400/10',
    queued: 'bg-sky-50 text-sky-700 ring-sky-600/20 dark:bg-sky-500/10 dark:text-sky-300 dark:ring-sky-400/20',
    running:
        'bg-sky-50 text-sky-700 ring-sky-600/20 dark:bg-sky-500/10 dark:text-sky-300 dark:ring-sky-400/20',
    inspected:
        'bg-emerald-50 text-emerald-700 ring-emerald-600/20 dark:bg-emerald-500/10 dark:text-emerald-300 dark:ring-emerald-400/20',
    failed: 'bg-rose-50 text-rose-700 ring-rose-600/20 dark:bg-rose-500/10 dark:text-rose-300 dark:ring-rose-400/20',
    unsupported:
        'bg-amber-50 text-amber-700 ring-amber-600/20 dark:bg-amber-500/10 dark:text-amber-300 dark:ring-amber-400/20',
};

/** One-line status label for the Opportunities index/detail — see App\Support\ApplicationInspection\SummarizeInspectionStates. */
export function InspectionStateBadge({
    summary,
}: {
    summary: InspectionSummary;
}) {
    const label = (() => {
        switch (summary.state) {
            case 'not_inspected':
                return 'Not inspected';
            case 'queued':
                return 'Inspection queued';
            case 'running':
                return 'Inspecting…';
            case 'inspected':
                return `Inspected — ${summary.field_count ?? 0} question${summary.field_count === 1 ? '' : 's'}`;
            case 'failed':
                return 'Inspection failed';
            case 'unsupported':
                return 'Unsupported ATS';
        }
    })();

    return (
        <span className="inline-flex flex-col gap-0.5">
            <span
                className={cn(
                    badgeClass,
                    INSPECTION_STATE_STYLES[summary.state],
                )}
            >
                {label}
            </span>
            {summary.latest_attempt_failed && (
                <span className="text-xs text-rose-600 dark:text-rose-400">
                    Latest attempt failed
                </span>
            )}
        </span>
    );
}
