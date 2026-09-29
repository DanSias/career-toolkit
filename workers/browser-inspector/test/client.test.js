import { test, describe } from 'node:test';
import assert from 'node:assert/strict';
import http from 'node:http';
import { createClient } from '../src/client.js';

// A minimal fake Career Toolkit server — no mocking library added
// solely for this; a real HTTP server on a random local port makes
// createClient()'s actual fetch() calls exercised end-to-end.
function startFakeServer(handler) {
    return new Promise((resolve) => {
        const server = http.createServer((req, res) => {
            let body = '';
            req.on('data', (chunk) => (body += chunk));
            req.on('end', () => {
                handler(req, body ? JSON.parse(body) : null, res);
            });
        });
        server.listen(0, '127.0.0.1', () => resolve(server));
    });
}

function stop(server) {
    return new Promise((resolve) => server.close(resolve));
}

describe('createClient.claim', () => {
    test('returns the parsed claim payload on 200', async () => {
        let receivedAuth;
        let receivedBody;
        const server = await startFakeServer((req, body, res) => {
            receivedAuth = req.headers.authorization;
            receivedBody = body;
            res.writeHead(200, { 'Content-Type': 'application/json' });
            res.end(JSON.stringify({ agent_run_id: 42, application_url: 'https://example.com', inspection_policy: { ats: 'greenhouse' } }));
        });

        const client = createClient({ baseUrl: `http://127.0.0.1:${server.address().port}`, token: 'secret-token', workerIdentity: 'ai-box-1' });
        const claimed = await client.claim();

        assert.equal(receivedAuth, 'Bearer secret-token');
        assert.deepEqual(receivedBody, { worker_identity: 'ai-box-1' });
        assert.equal(claimed.agent_run_id, 42);
        assert.equal(claimed.application_url, 'https://example.com');

        await stop(server);
    });

    test('returns null on 204 no content (no work available)', async () => {
        const server = await startFakeServer((req, body, res) => {
            res.writeHead(204);
            res.end();
        });

        const client = createClient({ baseUrl: `http://127.0.0.1:${server.address().port}`, token: 't', workerIdentity: 'w' });
        const claimed = await client.claim();

        assert.equal(claimed, null);
        await stop(server);
    });

    test('throws on an unexpected non-2xx/204 status', async () => {
        const server = await startFakeServer((req, body, res) => {
            res.writeHead(500);
            res.end('boom');
        });

        const client = createClient({ baseUrl: `http://127.0.0.1:${server.address().port}`, token: 't', workerIdentity: 'w' });

        await assert.rejects(() => client.claim(), /status 500/);
        await stop(server);
    });
});

describe('createClient.reportResult', () => {
    test('posts the result payload to the agent run and returns the parsed response', async () => {
        let receivedPath;
        let receivedBody;
        const server = await startFakeServer((req, body, res) => {
            receivedPath = req.url;
            receivedBody = body;
            res.writeHead(200, { 'Content-Type': 'application/json' });
            res.end(JSON.stringify({ accepted: true, duplicate: false }));
        });

        const client = createClient({ baseUrl: `http://127.0.0.1:${server.address().port}`, token: 't', workerIdentity: 'w' });
        const response = await client.reportResult(42, { status: 'succeeded' });

        assert.equal(receivedPath, '/api/worker/agent-runs/42/result');
        assert.deepEqual(receivedBody, { status: 'succeeded' });
        assert.equal(response.ok, true);
        assert.equal(response.body.accepted, true);

        await stop(server);
    });

    test('reports a non-ok status without throwing, exposing the response body', async () => {
        const server = await startFakeServer((req, body, res) => {
            res.writeHead(409, { 'Content-Type': 'application/json' });
            res.end(JSON.stringify({ accepted: false, reason: 'agent_run_not_running', current_status: 'failed' }));
        });

        const client = createClient({ baseUrl: `http://127.0.0.1:${server.address().port}`, token: 't', workerIdentity: 'w' });
        const response = await client.reportResult(42, { status: 'succeeded' });

        assert.equal(response.ok, false);
        assert.equal(response.status, 409);
        assert.equal(response.body.reason, 'agent_run_not_running');

        await stop(server);
    });
});
