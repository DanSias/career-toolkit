import ProjectCard from '@/components/career-data/project-card';
import type { RoleSummary } from '@/types/career-data';

export default function RoleBlock({ role }: { role: RoleSummary }) {
    return (
        <div className="border-l-2 border-neutral-200 pl-4 dark:border-neutral-800">
            <div className="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
                <h3 className="text-base font-semibold text-neutral-900 dark:text-neutral-100">
                    {role.title}
                </h3>
                <div className="flex items-center gap-2 text-sm text-neutral-500 dark:text-neutral-400">
                    <span>{role.date_label}</span>
                    {role.is_current && (
                        <span className="inline-flex items-center rounded-md bg-emerald-50 px-2 py-0.5 text-xs font-medium text-emerald-700 ring-1 ring-emerald-600/20 ring-inset dark:bg-emerald-500/10 dark:text-emerald-300 dark:ring-emerald-400/20">
                            Current
                        </span>
                    )}
                </div>
            </div>

            <p className="mt-0.5 text-xs text-neutral-500 dark:text-neutral-400">
                {role.fact_count} role-level{' '}
                {role.fact_count === 1 ? 'fact' : 'facts'}
            </p>

            {role.projects.length > 0 ? (
                <div className="mt-3 grid grid-cols-1 gap-2 md:grid-cols-2">
                    {role.projects.map((project) => (
                        <ProjectCard key={project.slug} project={project} />
                    ))}
                </div>
            ) : (
                <p className="mt-2 text-sm text-neutral-400 italic dark:text-neutral-600">
                    No projects recorded for this role.
                </p>
            )}
        </div>
    );
}
