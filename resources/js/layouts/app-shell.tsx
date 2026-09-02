import { Link } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { index as careerDataIndex } from '@/routes/career-data';

/**
 * Modest internal-tool shell: a fixed header with the product name and a
 * minimal nav, and a single content column. Reused by every Career Data
 * screen so future internal-tool screens have somewhere consistent to
 * land without redesigning the shell.
 */
export default function AppShell({ children }: { children: ReactNode }) {
    return (
        <div className="min-h-screen bg-neutral-50 text-neutral-900 dark:bg-neutral-950 dark:text-neutral-100">
            <header className="border-b border-neutral-200 bg-white dark:border-neutral-800 dark:bg-neutral-900">
                <div className="mx-auto flex max-w-6xl items-center gap-6 px-4 py-3 sm:px-6">
                    <span className="text-sm font-semibold tracking-tight">
                        Career Toolkit
                    </span>
                    <nav className="flex items-center gap-4 text-sm">
                        <Link
                            href={careerDataIndex.url()}
                            className="font-medium text-neutral-900 hover:text-neutral-600 dark:text-neutral-100 dark:hover:text-neutral-300"
                        >
                            Career Data
                        </Link>
                    </nav>
                </div>
            </header>
            <main className="mx-auto max-w-6xl px-4 py-6 sm:px-6">
                {children}
            </main>
        </div>
    );
}
