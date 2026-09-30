/**
 * Matches App\Support\ApplicationInspection\PresentWorkerAvailability's
 * shape exactly.
 */
export type WorkerStatus = {
    identity: string | null;
    worker_type: string | null;
    online: boolean;
    last_seen_at: string | null;
};
