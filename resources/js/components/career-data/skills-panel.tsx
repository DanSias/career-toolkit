import { skillCategoryLabel } from '@/components/badges';
import { cn } from '@/lib/utils';
import type { SkillSummary } from '@/types/career-data';

function groupByCategory(
    skills: SkillSummary[],
): [SkillSummary['category'], SkillSummary[]][] {
    const groups = new Map<SkillSummary['category'], SkillSummary[]>();

    for (const skill of skills) {
        const group = groups.get(skill.category) ?? [];
        group.push(skill);
        groups.set(skill.category, group);
    }

    return Array.from(groups.entries());
}

export default function SkillsPanel({ skills }: { skills: SkillSummary[] }) {
    const groups = groupByCategory(skills);

    return (
        <section className="rounded-lg border border-neutral-200 bg-white p-4 dark:border-neutral-800 dark:bg-neutral-900">
            <h2 className="text-sm font-semibold text-neutral-900 dark:text-neutral-100">
                Skills
            </h2>

            {skills.length === 0 ? (
                <p className="mt-2 text-sm text-neutral-400 italic dark:text-neutral-600">
                    No skills recorded.
                </p>
            ) : (
                <div className="mt-3 space-y-4">
                    {groups.map(([category, categorySkills]) => (
                        <div key={category}>
                            <h3 className="text-xs font-medium tracking-wide text-neutral-400 uppercase dark:text-neutral-500">
                                {skillCategoryLabel(category)}
                            </h3>
                            <ul className="mt-1.5 space-y-1">
                                {categorySkills.map((skill) => {
                                    const isUnassociated =
                                        skill.career_fact_count === 0 &&
                                        skill.project_count === 0;

                                    return (
                                        <li
                                            key={skill.slug}
                                            className={cn(
                                                'flex items-center justify-between gap-2 text-sm',
                                                isUnassociated &&
                                                    'text-neutral-400 dark:text-neutral-600',
                                            )}
                                            title={
                                                isUnassociated
                                                    ? 'Not yet associated with any CareerFact or Project'
                                                    : undefined
                                            }
                                        >
                                            <span>{skill.name}</span>
                                            <span className="shrink-0 text-xs text-neutral-400 dark:text-neutral-600">
                                                {skill.career_fact_count}{' '}
                                                {skill.career_fact_count === 1
                                                    ? 'fact'
                                                    : 'facts'}{' '}
                                                · {skill.project_count}{' '}
                                                {skill.project_count === 1
                                                    ? 'project'
                                                    : 'projects'}
                                            </span>
                                        </li>
                                    );
                                })}
                            </ul>
                        </div>
                    ))}
                </div>
            )}
        </section>
    );
}
