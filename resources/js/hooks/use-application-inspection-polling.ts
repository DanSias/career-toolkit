import { useEffect, useState } from 'react';
import { status as applicationStatus } from '@/routes/applications';
import type { ApplicationInspectionState } from '@/types/application-inspection';

const POLL_INTERVAL_MS = 2000;

/**
 * Tracks an Application's inspection state client-side, polling the
 * read-only status endpoint every ~2s while the latest inspection
 * WorkflowRun is pending/running, stopping immediately once it reaches
 * a terminal status. Deliberately does NOT auto-navigate anywhere on
 * completion, unlike useGenerationAttemptPolling — this page is both
 * the trigger and the results view, so there is nowhere else to go.
 * See docs/application-inspector.md.
 */
export function useApplicationInspectionPolling(
    applicationId: number,
    initial: ApplicationInspectionState,
): ApplicationInspectionState {
    const [state, setState] = useState<ApplicationInspectionState>(initial);

    useEffect(() => {
        setState(initial);
    }, [initial.workflow_run?.id, initial.workflow_run?.status]);

    useEffect(() => {
        const currentStatus = state.workflow_run?.status;

        if (
            !currentStatus ||
            currentStatus === 'succeeded' ||
            currentStatus === 'failed' ||
            currentStatus === 'cancelled'
        ) {
            return;
        }

        let cancelled = false;

        const poll = async () => {
            try {
                const response = await fetch(
                    applicationStatus.url({ application: applicationId }),
                    { headers: { Accept: 'application/json' } },
                );

                if (!response.ok || cancelled) {
                    return;
                }

                const next =
                    (await response.json()) as ApplicationInspectionState;

                if (cancelled) {
                    return;
                }

                setState(next);
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
    }, [applicationId, state.workflow_run?.id, state.workflow_run?.status]);

    return state;
}
