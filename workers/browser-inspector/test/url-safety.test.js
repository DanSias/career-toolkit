import { test, describe } from 'node:test';
import assert from 'node:assert/strict';
import {
    validateUrlStructure,
    resolvesToPrivateAddress,
} from '../src/url-safety.js';

describe('validateUrlStructure', () => {
    test('accepts a valid https URL', () => {
        const result = validateUrlStructure(
            'https://job-boards.greenhouse.io/anthropic/jobs/123',
        );
        assert.equal(result.valid, true);
    });

    test('accepts a valid http URL', () => {
        assert.equal(
            validateUrlStructure('http://example.com/jobs/1').valid,
            true,
        );
    });

    test('rejects a malformed URL', () => {
        const result = validateUrlStructure('not a url at all');
        assert.equal(result.valid, false);
        assert.equal(result.reason, 'malformed_url');
    });

    test('rejects file: scheme', () => {
        const result = validateUrlStructure('file:///etc/passwd');
        assert.equal(result.valid, false);
        assert.match(result.reason, /unsupported_scheme/);
    });

    test('rejects javascript: scheme', () => {
        const result = validateUrlStructure('javascript:alert(1)');
        assert.equal(result.valid, false);
        assert.match(result.reason, /unsupported_scheme/);
    });

    test('rejects data: scheme', () => {
        const result = validateUrlStructure(
            'data:text/html,<script>alert(1)</script>',
        );
        assert.equal(result.valid, false);
        assert.match(result.reason, /unsupported_scheme/);
    });

    test('rejects the localhost hostname', () => {
        const result = validateUrlStructure('http://localhost:8080/');
        assert.equal(result.valid, false);
        assert.equal(result.reason, 'loopback_hostname');
    });

    test('rejects an IPv4 loopback literal', () => {
        const result = validateUrlStructure('http://127.0.0.1/');
        assert.equal(result.valid, false);
        assert.equal(result.reason, 'private_ip_literal');
    });

    test('rejects an RFC1918 private IPv4 literal', () => {
        assert.equal(validateUrlStructure('http://192.168.1.1/').valid, false);
        assert.equal(validateUrlStructure('http://10.0.0.5/').valid, false);
        assert.equal(validateUrlStructure('http://172.16.0.1/').valid, false);
    });

    test('rejects a link-local IPv4 literal', () => {
        assert.equal(
            validateUrlStructure('http://169.254.169.254/').valid,
            false,
        );
    });

    test('rejects an IPv6 loopback literal', () => {
        const result = validateUrlStructure('http://[::1]/');
        assert.equal(result.valid, false);
        assert.equal(result.reason, 'private_ip_literal');
    });
});

describe('resolvesToPrivateAddress', () => {
    test('flags an unresolvable hostname as unsafe rather than proceeding', async () => {
        const url = new URL('https://this-host-does-not-exist.invalid/');
        const result = await resolvesToPrivateAddress(url);
        assert.equal(result.safe, false);
    });
});
