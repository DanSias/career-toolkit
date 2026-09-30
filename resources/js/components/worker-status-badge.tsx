import { relativeTimeFromNow } from '@/lib/relative-time';
import type { WorkerStatus } from '@/types/worker-status';

/**
 * "Browser Inspector ● Online / Last seen 3 seconds ago" — Career
 * Toolkit only ever OBSERVES worker presence here, never
 * starts/stops/restarts it. See
 * App\Support\ApplicationInspection\PresentWorkerAvailability.
 */
export function WorkerStatusBadge({ status }: { status: WorkerStatus }) {
    return (
        <div className="flex items-center gap-1.5 text-xs">
            <span
                className={
                    status.online
                        ? 'inline-block h-1.5 w-1.5 rounded-full bg-emerald-500'
                        : 'inline-block h-1.5 w-1.5 rounded-full bg-neutral-400 dark:bg-neutral-600'
                }
                aria-hidden="true"
            />
            <span
                className={
                    status.online
                        ? 'text-neutral-600 dark:text-neutral-300'
                        : 'text-neutral-400 dark:text-neutral-500'
                }
            >
                Browser Inspector {status.online ? 'online' : 'offline'}
                {status.last_seen_at && (
                    <span className="text-neutral-400 dark:text-neutral-500">
                        {' '}
                        · last seen {relativeTimeFromNow(status.last_seen_at)}
                    </span>
                )}
            </span>
        </div>
    );
}

/**
 * The explicit "nothing is wrong, it just hasn't reconnected yet"
 * explanation for a queued/running inspection while the worker is
 * offline — see PRODUCT GOAL: the user should never be left wondering
 * why nothing is happening.
 */
export function WaitingForWorkerNotice({ status }: { status: WorkerStatus }) {
    if (status.online) {
        return null;
    }

    return (
        <p className="mt-2 text-xs text-amber-600 dark:text-amber-400">
            Browser worker offline. Inspection will remain queued until it
            reconnects.
        </p>
    );
}
