#!/usr/bin/env node
// Development/test boundary for the worker. Phase 3 will drive
// src/inspect.js directly from its own poll loop rather than shelling
// out to this CLI — extraction logic lives in inspect.js/extractors/
// precisely so it stays reusable rather than embedded in argument
// handling. See README.md "CLI usage".
//
// Usage: node src/cli.js <url>
//
// stdout: exactly one JSON object (the InspectionResult) — nothing
// else is ever written there.
// stderr: human-readable diagnostic logging only.
// Exit codes: 0 = inspection technically completed (including an
// 'unsupported' or 'partial' inspection_outcome — those are
// successful executions); 1 = technical failure (status: 'failed');
// 2 = usage error (bad/missing argument, never reached the browser).
import { inspect } from './inspect.js';

const [, , url, ...rest] = process.argv;

if (!url || rest.length > 0) {
    console.error('Usage: node src/cli.js <url>');
    process.exit(2);
}

const result = await inspect(url);

process.stdout.write(`${JSON.stringify(result, null, 2)}\n`);

process.exit(result.status === 'succeeded' ? 0 : 1);
