import { useEffect, useState } from 'react';
import { status as workerStatus } from '@/routes/browser-worker';
import type { WorkerStatus } from '@/types/worker-status';

const POLL_INTERVAL_MS = 10000;

const OFFLINE_STATUS: WorkerStatus = {
    identity: null,
    worker_type: null,
    online: false,
    last_seen_at: null,
};

/**
 * Ambient worker-presence polling — used by the nav-bar badge and by
 * any "waiting for browser worker" message. Deliberately a slower,
 * independent poll from useApplicationInspectionPolling: worker
 * presence is a global fact, not scoped to one Application, so one
 * hook instance's fetches serve every consumer on the page rather than
 * each Application view adding its own.
 */
export function useWorkerStatusPolling(): WorkerStatus {
    const [status, setStatus] = useState<WorkerStatus>(OFFLINE_STATUS);

    useEffect(() => {
        let cancelled = false;

        const poll = async () => {
            try {
                const response = await fetch(workerStatus.url(), {
                    headers: { Accept: 'application/json' },
                });

                if (!response.ok || cancelled) {
                    return;
                }

                const next = (await response.json()) as WorkerStatus;

                if (!cancelled) {
                    setStatus(next);
                }
            } catch {
                // A transient network hiccup isn't worth surfacing —
                // the next tick tries again.
            }
        };

        void poll();
        const intervalId = window.setInterval(poll, POLL_INTERVAL_MS);

        return () => {
            cancelled = true;
            window.clearInterval(intervalId);
        };
    }, []);

    return status;
}
