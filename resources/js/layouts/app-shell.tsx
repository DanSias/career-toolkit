import { Link, usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';
import { index as careerDataIndex } from '@/routes/career-data';
import { index as jobsIndex } from '@/routes/jobs';

const NAV_ITEMS = [
    { label: 'Career Data', href: careerDataIndex.url(), match: '/' },
    { label: 'Jobs', href: jobsIndex.url(), match: '/jobs' },
];

/**
 * Modest internal-tool shell: a fixed header with the product name and a
 * minimal nav, and a single content column. Reused by every screen so
 * future internal-tool sections have somewhere consistent to land
 * without redesigning the shell.
 */
export default function AppShell({ children }: { children: ReactNode }) {
    const { url } = usePage();

    return (
        <div className="min-h-screen bg-neutral-50 text-neutral-900 dark:bg-neutral-950 dark:text-neutral-100">
            <header className="border-b border-neutral-200 bg-white dark:border-neutral-800 dark:bg-neutral-900">
                <div className="mx-auto flex max-w-6xl items-center gap-6 px-4 py-3 sm:px-6">
                    <span className="text-sm font-semibold tracking-tight">
                        Career Toolkit
                    </span>
                    <nav className="flex items-center gap-4 text-sm">
                        {NAV_ITEMS.map((item) => {
                            const isActive =
                                item.match === '/'
                                    ? url === '/'
                                    : url.startsWith(item.match);

                            return (
                                <Link
                                    key={item.href}
                                    href={item.href}
                                    className={cn(
                                        'font-medium',
                                        isActive
                                            ? 'text-neutral-900 dark:text-neutral-100'
                                            : 'text-neutral-500 hover:text-neutral-700 dark:text-neutral-400 dark:hover:text-neutral-200',
                                    )}
                                >
                                    {item.label}
                                </Link>
                            );
                        })}
                    </nav>
                </div>
            </header>
            <main className="mx-auto max-w-6xl px-4 py-6 sm:px-6">
                {children}
            </main>
        </div>
    );
}
