import { useEffect, useState } from 'react';

/**
 * Ticks once per second while `active` is true. Never tied to a
 * provider timeout or any completion estimate — purely an honest "how
 * long has this been running" display.
 *
 * `since`, when provided, is a real server timestamp (e.g. a
 * GenerationAttempt's started_at) the elapsed time is computed against
 * instead of the moment this hook became active — so a page reload
 * mid-generation immediately shows the correct elapsed time rather
 * than restarting at 0:00. Omitted (the default), this falls back to
 * the original behavior: counting from the moment `active` became
 * true, for callers with no durable timestamp to derive from.
 */
export function useElapsedTimer(
    active: boolean,
    since?: string | Date | null,
): number {
    const [elapsedSeconds, setElapsedSeconds] = useState(0);
    const sinceTime = since ? new Date(since).getTime() : null;

    useEffect(() => {
        if (!active) {
            setElapsedSeconds(0);
            return;
        }

        const startedAtMs = sinceTime ?? Date.now();

        const tick = () =>
            setElapsedSeconds(
                Math.max(0, Math.floor((Date.now() - startedAtMs) / 1000)),
            );
        tick();

        const intervalId = window.setInterval(tick, 1000);

        return () => window.clearInterval(intervalId);
    }, [active, sinceTime]);

    return elapsedSeconds;
}
