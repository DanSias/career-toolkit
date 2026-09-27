import { useEffect, useState } from 'react';
import { router } from '@inertiajs/react';
import { show as generationAttemptShow } from '@/routes/generation-attempts';
import type { GenerationAttempt } from '@/types/generation-attempt';

const POLL_INTERVAL_MS = 2000;

/**
 * Tracks a GenerationAttempt's durable status client-side, polling the
 * read-only endpoint every ~2s while it's queued/running and stopping
 * immediately once it reaches succeeded/failed. On success, navigates
 * to the attempt's result_url automatically. See
 * docs/job-analysis-generation.md "Async Job Analysis".
 *
 * Re-syncs from `initialAttempt` whenever it changes (e.g. after an
 * Inertia redirect reloads this page's props with a newly queued or
 * still-active attempt) — this is how "Generate Analysis" clicked
 * again, or a browser reload mid-generation, both pick up correctly
 * without a full remount.
 */
export function useGenerationAttemptPolling(
    initialAttempt: GenerationAttempt | null,
): GenerationAttempt | null {
    const [attempt, setAttempt] = useState<GenerationAttempt | null>(
        initialAttempt,
    );

    useEffect(() => {
        setAttempt(initialAttempt);
    }, [initialAttempt?.id, initialAttempt?.status]);

    useEffect(() => {
        if (
            !attempt ||
            attempt.status === 'succeeded' ||
            attempt.status === 'failed'
        ) {
            return;
        }

        let cancelled = false;

        const poll = async () => {
            try {
                const response = await fetch(
                    generationAttemptShow.url({
                        generationAttempt: attempt.id,
                    }),
                    {
                        headers: { Accept: 'application/json' },
                    },
                );

                if (!response.ok || cancelled) {
                    return;
                }

                const next = (await response.json()) as GenerationAttempt;

                if (cancelled) {
                    return;
                }

                setAttempt(next);

                if (next.status === 'succeeded' && next.result_url) {
                    router.visit(next.result_url);
                }
            } catch {
                // A transient network hiccup isn't worth surfacing —
                // the next tick tries again.
            }
        };

        const intervalId = window.setInterval(poll, POLL_INTERVAL_MS);

        return () => {
            cancelled = true;
            window.clearInterval(intervalId);
        };
    }, [attempt?.id, attempt?.status]);

    return attempt;
}
