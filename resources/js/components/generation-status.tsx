import { useElapsedTimer } from '@/hooks/use-elapsed-timer';
import { formatElapsedTime } from '@/lib/format-elapsed-time';

/**
 * Honest replacement for a determinate-looking progress bar during a
 * long-running local-AI generation request: an indeterminate activity
 * bar (loops continuously, never reaches 100%) plus a live elapsed-time
 * counter. Never implies a percentage complete, and never counts down
 * from the provider timeout — this is purely "how long has this been
 * running," shown only while `active`.
 *
 * Two usage modes:
 * - Client-timer (Job Match, Resume — still synchronous): pass only
 *   `active`/`label`. The timer counts from the moment `active` became
 *   true, same as before this component learned about queued/running
 *   server state.
 * - Server-truth (Job Analysis — queued/polled): also pass `queued`
 *   and/or `startedAt`. When `queued` is true, shows queuedLabel
 *   instead of a timer (there's nothing to count yet). When `startedAt`
 *   is given, the timer derives from that real timestamp instead of
 *   this component's own mount time — so a reload mid-generation shows
 *   the correct elapsed time immediately rather than restarting at
 *   0:00. See docs/job-analysis-generation.md "Async Job Analysis".
 */
export function GenerationStatus({
    active,
    label,
    queued = false,
    queuedLabel,
    startedAt = null,
}: {
    active: boolean;
    label: string;
    queued?: boolean;
    queuedLabel?: string;
    startedAt?: string | Date | null;
}) {
    const elapsedSeconds = useElapsedTimer(active && !queued, startedAt);

    if (!active) {
        return null;
    }

    return (
        <div className="mt-2 w-48" role="status" aria-live="polite">
            <div className="h-1 overflow-hidden rounded-full bg-neutral-200 dark:bg-neutral-800">
                <div className="animate-generation-indeterminate h-full w-1/3 rounded-full bg-neutral-900 dark:bg-neutral-100" />
            </div>
            <p className="mt-1 text-xs text-neutral-500 dark:text-neutral-400">
                {queued
                    ? (queuedLabel ?? `${label}…`)
                    : `${label}… ${formatElapsedTime(elapsedSeconds)} elapsed`}
            </p>
        </div>
    );
}
