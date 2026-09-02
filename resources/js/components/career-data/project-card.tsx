import { VisibilityBadge } from '@/components/badges';
import type { ProjectSummary } from '@/types/career-data';

export default function ProjectCard({ project }: { project: ProjectSummary }) {
    return (
        <div className="rounded-lg border border-neutral-200 bg-white p-3 dark:border-neutral-800 dark:bg-neutral-900">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <h4 className="text-sm font-medium text-neutral-900 dark:text-neutral-100">
                    {project.name}
                </h4>
                <div className="flex items-center gap-2">
                    {project.visibility && (
                        <VisibilityBadge value={project.visibility} />
                    )}
                    <span className="text-xs text-neutral-500 dark:text-neutral-400">
                        {project.fact_count}{' '}
                        {project.fact_count === 1 ? 'fact' : 'facts'}
                    </span>
                </div>
            </div>

            {project.description && (
                <p className="mt-1.5 text-sm text-neutral-600 dark:text-neutral-400">
                    {project.description}
                </p>
            )}

            {project.skills.length > 0 && (
                <div className="mt-2 flex flex-wrap gap-1.5">
                    {project.skills.map((skill) => (
                        <span
                            key={skill.slug}
                            className="rounded bg-neutral-100 px-1.5 py-0.5 text-xs text-neutral-600 dark:bg-neutral-800 dark:text-neutral-300"
                        >
                            {skill.name}
                        </span>
                    ))}
                </div>
            )}
        </div>
    );
}
