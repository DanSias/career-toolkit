// Thin HTTP client for the Career Toolkit browser-worker protocol.
// Deliberately has nothing to do with Playwright/browser pages — the
// worker token is only ever used here, never passed into inspect().
function authHeaders(token) {
    return {
        'Content-Type': 'application/json',
        Accept: 'application/json',
        Authorization: `Bearer ${token}`,
    };
}

export function createClient({ baseUrl, token, workerIdentity }) {
    async function claim() {
        const response = await fetch(`${baseUrl}/api/worker/agent-runs/claim`, {
            method: 'POST',
            headers: authHeaders(token),
            body: JSON.stringify({ worker_identity: workerIdentity }),
        });

        if (response.status === 204) {
            return null;
        }
        if (!response.ok) {
            throw new Error(`claim request failed with status ${response.status}`);
        }

        return response.json();
    }

    async function reportResult(agentRunId, payload) {
        const response = await fetch(`${baseUrl}/api/worker/agent-runs/${agentRunId}/result`, {
            method: 'POST',
            headers: authHeaders(token),
            body: JSON.stringify(payload),
        });

        let body = null;
        try {
            body = await response.json();
        } catch {
            // No/invalid JSON body — leave body null, status still reported.
        }

        return { ok: response.ok, status: response.status, body };
    }

    return { claim, reportResult };
}
