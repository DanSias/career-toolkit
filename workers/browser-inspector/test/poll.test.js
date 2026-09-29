import { test, describe } from 'node:test';
import assert from 'node:assert/strict';
import { runOnce, deliverResult, runForever } from '../src/poll.js';

// A fake client — poll.js's own contract with client.js (claim()/
// reportResult()) is tested directly here, never against a real
// browser or real HTTP; client.test.js already covers the real HTTP
// wiring, and greenhouse-extractor.test.js already covers inspect().
function fakeClient({ claims = [], reportResultImpl } = {}) {
    let claimIndex = 0;
    const reportedCalls = [];

    return {
        calls: reportedCalls,
        async claim() {
            const next = claims[claimIndex] ?? null;
            claimIndex += 1;
            return next;
        },
        async reportResult(agentRunId, payload) {
            reportedCalls.push({ agentRunId, payload });
            if (reportResultImpl) {
                return reportResultImpl(agentRunId, payload, reportedCalls.length);
            }
            return { ok: true, status: 200, body: { accepted: true, duplicate: false } };
        },
    };
}

const silentLog = () => {};
const noRetryConfig = { resultRetryAttempts: 1, resultRetryBackoffMs: 0 };

describe('runOnce', () => {
    test('returns false and never calls inspect when there is no claimed work', async () => {
        const client = fakeClient({ claims: [null] });
        let inspectCalled = false;

        const gotWork = await runOnce(client, noRetryConfig, silentLog, async () => {
            inspectCalled = true;
            return { status: 'succeeded' };
        });

        assert.equal(gotWork, false);
        assert.equal(inspectCalled, false);
        assert.equal(client.calls.length, 0);
    });

    test('inspects the claimed URL and reports the result', async () => {
        const client = fakeClient({ claims: [{ agent_run_id: 7, application_url: 'https://example.com/jobs/1' }] });
        let inspectedUrl = null;

        const gotWork = await runOnce(client, noRetryConfig, silentLog, async (url) => {
            inspectedUrl = url;
            return { status: 'succeeded', inspection_outcome: 'complete', fields: [] };
        });

        assert.equal(gotWork, true);
        assert.equal(inspectedUrl, 'https://example.com/jobs/1');
        assert.equal(client.calls.length, 1);
        assert.equal(client.calls[0].agentRunId, 7);
        assert.equal(client.calls[0].payload.status, 'succeeded');
    });
});

describe('deliverResult', () => {
    test('delivers on the first attempt without retrying on success', async () => {
        const client = fakeClient();

        const response = await deliverResult(client, 1, { status: 'succeeded' }, { attempts: 3, backoffMs: 0 }, silentLog);

        assert.equal(response.ok, true);
        assert.equal(client.calls.length, 1);
    });

    test('does not retry a stale (409) rejection — that is a completed delivery, not a transient failure', async () => {
        const client = fakeClient({
            reportResultImpl: () => ({ ok: false, status: 409, body: { accepted: false, reason: 'agent_run_not_running' } }),
        });

        const response = await deliverResult(client, 1, { status: 'succeeded' }, { attempts: 3, backoffMs: 0 }, silentLog);

        assert.equal(response.status, 409);
        assert.equal(client.calls.length, 1);
    });

    test('retries a thrown transport error up to the configured attempt count, then gives up', async () => {
        const client = fakeClient({
            reportResultImpl: () => {
                throw new Error('network unreachable');
            },
        });

        const response = await deliverResult(client, 1, { status: 'succeeded' }, { attempts: 3, backoffMs: 0 }, silentLog);

        assert.equal(response, null);
        assert.equal(client.calls.length, 3);
    });

    test('recovers after a transient failure on a later attempt', async () => {
        let attempt = 0;
        const client = fakeClient({
            reportResultImpl: () => {
                attempt += 1;
                if (attempt < 2) {
                    throw new Error('temporary blip');
                }
                return { ok: true, status: 200, body: { accepted: true, duplicate: false } };
            },
        });

        const response = await deliverResult(client, 1, { status: 'succeeded' }, { attempts: 3, backoffMs: 0 }, silentLog);

        assert.equal(response.ok, true);
        assert.equal(client.calls.length, 2);
    });
});

describe('runForever', () => {
    test('stops as soon as shouldStop() reports true, having polled at least once', async () => {
        const client = fakeClient({ claims: [null, null, null] });
        let iterations = 0;

        await runForever(
            client,
            { ...noRetryConfig, pollIntervalMs: 0, maxBackoffMs: 0, careerToolkitUrl: 'http://fake', workerIdentity: 'test' },
            silentLog,
            () => {
                iterations += 1;
                return iterations > 2;
            },
            async () => ({ status: 'succeeded' }),
        );

        assert.ok(iterations > 2);
    });
});
