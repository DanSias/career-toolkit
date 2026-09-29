import { test, describe } from 'node:test';
import assert from 'node:assert/strict';
import { execFile } from 'node:child_process';
import { promisify } from 'node:util';
import { fileURLToPath } from 'node:url';
import path from 'node:path';
import {
    succeeded,
    unsupported,
    failed,
    InspectionOutcome,
    FailureCategory,
} from '../src/result.js';

const execFileAsync = promisify(execFile);
const __dirname = path.dirname(fileURLToPath(import.meta.url));
const cliPath = path.join(__dirname, '../src/cli.js');

describe('result builders', () => {
    test('succeeded() produces the documented complete shape', () => {
        const result = succeeded({
            ats: 'greenhouse',
            requestedUrl: 'https://job-boards.greenhouse.io/co/jobs/1',
            finalUrl: 'https://job-boards.greenhouse.io/co/jobs/1',
            fields: [],
            warnings: [],
            diagnostics: {},
        });
        assert.equal(result.status, 'succeeded');
        assert.equal(result.inspection_outcome, InspectionOutcome.Complete);
        assert.equal(result.ats, 'greenhouse');
        assert.deepEqual(result.fields, []);
    });

    test('unsupported() reports a successful technical execution, not a failure', () => {
        const result = unsupported({
            requestedUrl: 'https://example.com/careers',
            finalUrl: 'https://example.com/careers',
            warnings: ['no supported ATS detected'],
            diagnostics: {},
        });
        assert.equal(result.status, 'succeeded');
        assert.equal(result.inspection_outcome, InspectionOutcome.Unsupported);
        assert.equal(result.ats, null);
        assert.deepEqual(result.fields, []);
    });

    test('failed() carries a failure_category the worker is authorized to produce', () => {
        const result = failed({
            failureCategory: FailureCategory.NavigationTimeout,
            failureMessage: 'timed out',
            diagnostics: {},
        });
        assert.equal(result.status, 'failed');
        assert.equal(result.failure_category, 'navigation_timeout');
        assert.ok(!('inspection_outcome' in result));
    });

    test('the worker-authorized failure categories never include claim_timeout', () => {
        assert.ok(!Object.values(FailureCategory).includes('claim_timeout'));
    });
});

describe('CLI stdout/exit-code contract', () => {
    test('prints usage to stderr and exits 2 when no URL is given', async () => {
        await assert.rejects(execFileAsync('node', [cliPath]), (err) => {
            assert.equal(err.code, 2);
            assert.equal(err.stdout, '');
            assert.match(err.stderr, /Usage:/);
            return true;
        });
    });

    test('rejects a private-IP URL before navigation: valid JSON on stdout, exit code 1, no stack trace on stdout', async () => {
        await assert.rejects(
            execFileAsync('node', [cliPath, 'http://127.0.0.1/']),
            (err) => {
                assert.equal(err.code, 1);
                const parsed = JSON.parse(err.stdout);
                assert.equal(parsed.status, 'failed');
                assert.match(parsed.failure_message, /private_ip_literal/);
                assert.ok(
                    !err.stdout.includes('at Object.<anonymous>'),
                    'stdout must not contain a stack trace',
                );
                return true;
            },
        );
    });

    test('rejects a malformed URL: valid JSON on stdout, exit code 1', async () => {
        await assert.rejects(
            execFileAsync('node', [cliPath, 'not-a-url']),
            (err) => {
                assert.equal(err.code, 1);
                const parsed = JSON.parse(err.stdout);
                assert.equal(parsed.status, 'failed');
                return true;
            },
        );
    });
});
