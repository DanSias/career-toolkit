import type { EducationSummary } from '@/types/career-data';

function yearLabel(education: EducationSummary): string | null {
    if (education.start_year && education.end_year) {
        return `${education.start_year}–${education.end_year}`;
    }

    if (education.end_year) {
        return String(education.end_year);
    }

    if (education.start_year) {
        return `${education.start_year}–`;
    }

    return null;
}

export default function EducationPanel({
    education,
}: {
    education: EducationSummary[];
}) {
    return (
        <section className="rounded-lg border border-neutral-200 bg-white p-4 dark:border-neutral-800 dark:bg-neutral-900">
            <h2 className="text-sm font-semibold text-neutral-900 dark:text-neutral-100">
                Education
            </h2>

            {education.length === 0 ? (
                <p className="mt-2 text-sm text-neutral-400 italic dark:text-neutral-600">
                    No education records.
                </p>
            ) : (
                <ul className="mt-3 space-y-3">
                    {education.map((item) => (
                        <li key={item.id} className="text-sm">
                            <p className="font-medium text-neutral-900 dark:text-neutral-100">
                                {item.degree}
                                {item.field_of_study && (
                                    <span className="font-normal">
                                        {' '}
                                        in {item.field_of_study}
                                    </span>
                                )}
                            </p>
                            <p className="text-neutral-500 dark:text-neutral-400">
                                {item.institution}
                                {yearLabel(item) && (
                                    <span> · {yearLabel(item)}</span>
                                )}
                            </p>
                        </li>
                    ))}
                </ul>
            )}
        </section>
    );
}
