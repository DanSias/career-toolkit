import RoleBlock from '@/components/career-data/role-block';
import type { EmployerSummary } from '@/types/career-data';

export default function EmployerSection({
    employer,
}: {
    employer: EmployerSummary;
}) {
    return (
        <section className="rounded-lg border border-neutral-200 bg-white p-4 sm:p-5 dark:border-neutral-800 dark:bg-neutral-900">
            <div className="flex flex-wrap items-baseline justify-between gap-2">
                <h2 className="text-lg font-semibold text-neutral-900 dark:text-neutral-100">
                    {employer.name}
                </h2>
                {employer.fact_count > 0 && (
                    <span className="text-xs text-neutral-500 dark:text-neutral-400">
                        {employer.fact_count} employer-level{' '}
                        {employer.fact_count === 1 ? 'fact' : 'facts'}
                    </span>
                )}
            </div>

            {employer.description && (
                <p className="mt-1 text-sm text-neutral-600 dark:text-neutral-400">
                    {employer.description}
                </p>
            )}

            {employer.roles.length > 0 ? (
                <div className="mt-4 space-y-6">
                    {employer.roles.map((role) => (
                        <RoleBlock key={role.id} role={role} />
                    ))}
                </div>
            ) : (
                <p className="mt-3 text-sm text-neutral-400 italic dark:text-neutral-600">
                    No roles recorded for this employer.
                </p>
            )}
        </section>
    );
}
