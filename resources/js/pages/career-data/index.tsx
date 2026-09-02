import { Head } from '@inertiajs/react';
import EducationPanel from '@/components/career-data/education-panel';
import EmployerSection from '@/components/career-data/employer-section';
import FactSummaryRow from '@/components/career-data/fact-summary-row';
import SkillsPanel from '@/components/career-data/skills-panel';
import AppShell from '@/layouts/app-shell';
import type { CareerDataIndexProps } from '@/types/career-data';

export default function CareerDataIndex({
    profile,
    employers,
    education,
    skills,
}: CareerDataIndexProps) {
    return (
        <AppShell>
            <Head title="Career Data" />

            {profile === null ? (
                <div className="rounded-lg border border-dashed border-neutral-300 p-8 text-center dark:border-neutral-700">
                    <p className="text-sm text-neutral-500 dark:text-neutral-400">
                        No career profile found. Run{' '}
                        <code className="font-mono">
                            php artisan career:import
                        </code>{' '}
                        to populate the canonical dataset.
                    </p>
                </div>
            ) : (
                <div className="space-y-6">
                    <div>
                        <h1 className="text-xl font-semibold text-neutral-900 dark:text-neutral-100">
                            {profile.name}
                        </h1>
                        <p className="text-sm text-neutral-500 dark:text-neutral-400">
                            Canonical career data
                        </p>
                    </div>

                    {profile.facts.length > 0 && (
                        <section>
                            <h2 className="text-sm font-semibold text-neutral-900 dark:text-neutral-100">
                                Career-wide facts
                            </h2>
                            <div className="mt-2 space-y-2">
                                {profile.facts.map((fact) => (
                                    <FactSummaryRow
                                        key={fact.key}
                                        fact={fact}
                                    />
                                ))}
                            </div>
                        </section>
                    )}

                    <div className="grid grid-cols-1 gap-6 lg:grid-cols-[minmax(0,1fr)_320px]">
                        <div className="space-y-6">
                            {employers.length === 0 ? (
                                <p className="text-sm text-neutral-400 italic dark:text-neutral-600">
                                    No employers recorded.
                                </p>
                            ) : (
                                employers.map((employer) => (
                                    <EmployerSection
                                        key={employer.id}
                                        employer={employer}
                                    />
                                ))
                            )}
                        </div>

                        <div className="space-y-6">
                            <EducationPanel education={education} />
                            <SkillsPanel skills={skills} />
                        </div>
                    </div>
                </div>
            )}
        </AppShell>
    );
}
