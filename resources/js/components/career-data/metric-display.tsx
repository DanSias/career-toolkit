import type { MetricDetail } from '@/types/career-data';

export default function MetricDisplay({ metric }: { metric: MetricDetail }) {
    return (
        <div className="rounded-lg border border-neutral-200 bg-neutral-50 p-4 dark:border-neutral-800 dark:bg-neutral-900/50">
            <p className="text-2xl font-semibold text-neutral-900 dark:text-neutral-100">
                {metric.display}
            </p>

            <dl className="mt-3 grid grid-cols-2 gap-x-4 gap-y-1.5 text-xs text-neutral-500 sm:grid-cols-4 dark:text-neutral-400">
                <div>
                    <dt className="font-medium text-neutral-400 dark:text-neutral-500">
                        Value
                    </dt>
                    <dd>
                        {metric.is_range
                            ? `${metric.value} – ${metric.value_max}`
                            : metric.value}
                    </dd>
                </div>
                <div>
                    <dt className="font-medium text-neutral-400 dark:text-neutral-500">
                        Unit
                    </dt>
                    <dd>{metric.unit}</dd>
                </div>
                {metric.comparator && (
                    <div>
                        <dt className="font-medium text-neutral-400 dark:text-neutral-500">
                            Comparator
                        </dt>
                        <dd>{metric.comparator}</dd>
                    </div>
                )}
                {metric.is_range && (
                    <div>
                        <dt className="font-medium text-neutral-400 dark:text-neutral-500">
                            Bounded range
                        </dt>
                        <dd>Yes</dd>
                    </div>
                )}
            </dl>

            {metric.scope_note && (
                <p className="mt-3 text-sm text-neutral-600 dark:text-neutral-400">
                    <span className="font-medium text-neutral-700 dark:text-neutral-300">
                        Scope:{' '}
                    </span>
                    {metric.scope_note}
                </p>
            )}

            {/* Guardrails are internal canonical-data constraints, not marketing copy —
                kept visually distinct (warning-styled) from the fact/metric itself. */}
            {metric.guardrail && (
                <div className="mt-3 rounded-md border border-amber-200 bg-amber-50 p-2.5 text-sm text-amber-900 dark:border-amber-900/50 dark:bg-amber-500/10 dark:text-amber-200">
                    <span className="font-medium">Guardrail — </span>
                    {metric.guardrail}
                </div>
            )}
        </div>
    );
}
