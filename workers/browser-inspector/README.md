# Browser Inspector Worker

Career Toolkit's read-only Application Inspector browser worker. See
`docs/application-inspector.md` in the main repository for the full
architecture; this document is operational, not architectural.

## Purpose

Given a job-application URL, open it in a real browser, identify
whether it's a supported ATS, and extract a structured, honest
inventory of the form's fields/questions — nothing more.

## Scope

**Supports:** Greenhouse only, anonymous/read-only inspection.

**Explicit non-capabilities** — none of the following exist anywhere in
this worker's code, not as a disabled option, as an absent one:

- No form filling, typing, selecting, or file upload.
- No clicking beyond the initial page load (no `submit()`,
  `click(selector)`, or any generic action API).
- No application submission of any kind.
- No LLM, local or cloud — extraction is fully deterministic.
- No browser-agent framework (browser-use/Stagehand/Skyvern) and no
  visual/computer-use reasoning.
- No persistent browser profile, cookies, or session state.
- No Career Profile access, no Career Toolkit database access, no
  Laravel/PHP dependency of any kind.
- No credentials of any kind are read, stored, or required.

## Architecture boundary

This worker is independently built and independently deployed — a
Docker component, not a Laravel dependency. `src/inspect.js` is the
reusable extraction boundary, untouched since Phase 2. `src/cli.js`
remains a thin one-shot development/test wrapper around it.
`src/poll.js` is the production runtime: it claims work from Career
Toolkit over HTTP, calls the same `inspect()`, and reports the result
back — see "Worker runtime" below. See `docs/application-inspector.md`
for the full protocol.

## Prerequisites

- Docker (for the intended runtime).
- Node.js >= 22 and `npx playwright install chromium` (for local
  development only — the Docker image already bundles a
  version-matched Chromium).

## Build

```sh
cd workers/browser-inspector
docker build -t browser-inspector:0.1.0 .
```

## Version pinning rule

The base image tag and the `playwright` npm package version **must
match exactly**. Using `mcr.microsoft.com/playwright:latest` caused a
real, reproduced version mismatch during the investigation proof of
concept (the tag resolved to an older image than the current npm
release expected, and the container failed to launch at all —
`Executable doesn't exist`). This is why the Dockerfile pins
`mcr.microsoft.com/playwright:v1.63.0-noble` alongside
`"playwright": "1.63.0"` in `package.json`, never `latest`.

## CLI usage

```sh
node src/cli.js <url>
# or, once dependencies are installed:
npm run inspect -- <url>
```

- **stdout**: exactly one JSON object (the `InspectionResult`) — never
  anything else.
- **stderr**: human-readable diagnostics only (e.g. the usage message
  on a bad invocation).
- **Exit codes**: `0` = the inspection technically completed (this
  includes an `unsupported` or `partial` `inspection_outcome` — those
  are successful executions); `1` = technical failure
  (`status: "failed"`); `2` = usage error (bad/missing argument, never
  reached the browser).

## Worker runtime

`src/poll.js` is the default container entrypoint (`Dockerfile`). It
loops: claim → inspect → report, forever, until `SIGINT`/`SIGTERM`.

```sh
node src/poll.js
# or:
npm run poll
```

### Configuration (`src/config.js`)

| Variable | Required | Default | Meaning |
|---|---|---|---|
| `CAREER_TOOLKIT_URL` | yes | — | Base URL of Career Toolkit, e.g. `http://192.168.1.50:8000`. The worker only ever makes **outbound** requests to this URL — it never listens on any inbound port. |
| `BROWSER_WORKER_TOKEN` | yes | — | Shared-secret bearer token. Must match Career Toolkit's `BROWSER_WORKER_TOKEN` env var exactly. Used only in this process's HTTP requests to Career Toolkit — never passed into `inspect()`, Playwright, or any browser page context. |
| `BROWSER_WORKER_IDENTITY` | no | `browser-inspector` | Sent as `worker_identity` on every claim; recorded on the `AgentRun` for audit only, not used for authorization. |
| `BROWSER_WORKER_POLL_INTERVAL_MS` | no | `5000` | Delay between claim attempts when no work is available. |
| `BROWSER_WORKER_MAX_BACKOFF_MS` | no | `60000` | Ceiling for exponential backoff after a claim/poll error (network failure, Career Toolkit unreachable, etc). |
| `BROWSER_WORKER_RESULT_RETRY_ATTEMPTS` | no | `3` | Attempts to deliver one result before giving up. |
| `BROWSER_WORKER_RESULT_RETRY_BACKOFF_MS` | no | `2000` | Linear backoff unit between result-delivery retries. |

Missing a required variable fails fast at startup (`loadConfig()`
throws) rather than running misconfigured.

### Loop behavior

- **Claim**: `POST {CAREER_TOOLKIT_URL}/api/worker/agent-runs/claim`. A
  `204` means no work; the loop sleeps `BROWSER_WORKER_POLL_INTERVAL_MS`
  and tries again. A `200` returns exactly `agent_run_id`,
  `application_url`, and `inspection_policy` — nothing about the
  underlying `Application`, `JobPosting`, or Career Profile.
- **Inspect**: the claimed `application_url` is passed to the
  unmodified `inspect()` from Phase 2 — the worker's extraction
  behavior is identical regardless of transport.
- **Report**: `POST {CAREER_TOOLKIT_URL}/api/worker/agent-runs/{id}/result`
  with `inspect()`'s own return value as the JSON body verbatim — it
  already matches Career Toolkit's validated contract field-for-field
  (see "JSON output contract" below).
- **Result-delivery retry** (`src/poll.js`'s `deliverResult()`) only
  retries a **transport-level** failure (a thrown exception — network
  unreachable, connection reset). Any response that actually came back
  from Career Toolkit — including a `409` stale-result rejection — is
  treated as a completed delivery and is never retried; the browser
  inspection itself is never re-run to retry a delivery. If all
  transport retries are exhausted, the result is dropped and the
  `AgentRun`'s claim lease simply expires — Career Toolkit's own claim
  recovery marks it `failed`/`claim_timeout` on the next poll from any
  worker, and the user can trigger a fresh retry from the UI.
- **Claim/poll errors** (Career Toolkit unreachable, non-2xx/204
  response) back off exponentially up to `BROWSER_WORKER_MAX_BACKOFF_MS`
  rather than retrying immediately in a tight loop.

## JSON output contract

### Success — complete

```json
{
    "status": "succeeded",
    "inspection_outcome": "complete",
    "ats": "greenhouse",
    "requested_url": "https://job-boards.greenhouse.io/anthropic/jobs/4461450008",
    "final_url": "https://job-boards.greenhouse.io/anthropic/jobs/4461450008",
    "fields": [
        {
            "position": 0,
            "external_field_id": "first_name",
            "raw_label": "First Name*",
            "label_source": "dom_label_for",
            "control_type": "text",
            "required": true,
            "options": null,
            "section": "Apply for this job",
            "extraction_source": "dom"
        }
    ],
    "warnings": [],
    "diagnostics": {
        "total_ms": 3561,
        "navigation_ms": 3329,
        "extraction_ms": 50,
        "page_title": "Job Application for Account Executive, AI Native at Anthropic",
        "timestamp": "2026-09-29T17:49:21.946Z",
        "field_count": 31,
        "unresolved_label_count": 8,
        "hidden_field_count": 0,
        "button_count": 19
    }
}
```

`position`/`external_field_id`/`raw_label`/`label_source`/
`control_type`/`required`/`options`/`section`/`extraction_source` map
directly onto Career Toolkit's `application_questions` table
(`ApplicationQuestion` model) — see `app/Enums/ApplicationQuestionLabelSource.php`
and `app/Enums/ApplicationQuestionExtractionSource.php` for the
authoritative enum values, hand-synced in `src/result.js` (see
"Contract alignment" below). The worker does not and cannot know
`application_id`/`agent_run_id` — those belong to Career Toolkit,
assigned when a future transport layer persists this result.

### Success — unsupported ATS

Not a failure — the technical execution succeeded; the page just isn't
a supported ATS.

```json
{
    "status": "succeeded",
    "inspection_outcome": "unsupported",
    "ats": null,
    "requested_url": "https://example.com",
    "final_url": "https://example.com/",
    "fields": [],
    "warnings": [],
    "diagnostics": {
        "total_ms": 2870,
        "navigation_ms": 2406,
        "extraction_ms": null,
        "page_title": "Example Domain",
        "timestamp": "..."
    }
}
```

### Failure

```json
{
    "status": "failed",
    "failure_category": "navigation_timeout",
    "failure_message": "page.goto: Timeout 45000ms exceeded.",
    "diagnostics": {
        "total_ms": 45012,
        "navigation_ms": null,
        "extraction_ms": null,
        "page_title": null,
        "timestamp": "..."
    }
}
```

`failure_category` is one of `navigation_timeout` / `unsupported_ats` /
`unexpected_error` — a strict **subset** of Career Toolkit's
`AgentRunFailureCategory` enum. The fourth value, `claim_timeout`,
exists only for Career Toolkit's own detection of a worker that claimed
work and never reported back; it is definitionally unobservable from
inside this process and this worker never produces it.

## Contract alignment

`src/result.js` hand-copies the exact string values from Career
Toolkit's Phase 1 backed enums (`ApplicationQuestionLabelSource`,
`ApplicationQuestionExtractionSource`, `InspectionOutcome`,
`AgentRunFailureCategory`) rather than importing PHP or generating
code from it. For a contract this small (four enums, under 20 total
values), a hand-synced, directly-diffable copy is simpler and more
transparent than a codegen step — audit by diffing `src/result.js`
against the four `app/Enums/*.php` files directly.

## Extraction strategy

```
DOM: native <label for>/wrapping <label>
  -> aria-label / aria-labelledby attributes
  -> placeholder
  -> structural/proximity heuristic (nearest preceding readable text)
  -> unresolved
```

A single whole-page `ariaSnapshot()` call (never one call per field)
cross-checks every DOM-resolved label against the page's own
accessibility tree: `extraction_source` becomes `"both"` when they
agree, `"dom"` otherwise. ARIA is **not** used as an independent
label-recovery path for fields the DOM chain — including its proximity
fallback — already failed to resolve: no real Greenhouse or Lever field
observed during the investigation was ever rescued by ARIA after every
DOM tier had already failed (see the Lever university-selector finding
in the browser-automation investigation, where DOM and ARIA both failed
identically), and inventing a `label_source` for that unobserved case
would misrepresent provenance rather than honestly report it.

**Required-state** uses only the `required` attribute / `aria-required`
— never an asterisk-in-label heuristic. Every asterisk-marked required
field observed in the real Anthropic smoke-test capture also carried
the real `required` attribute, so the asterisk was never independently
necessary. One real, disclosed consequence: the live smoke test found
Greenhouse's résumé upload field reports `required: false` even though
it is, in practice, required — Greenhouse enforces that particular
field via client-side JavaScript rather than the native `required`
attribute, which this deterministic extractor has no way to observe.
`required: false` here means "no required attribute or aria-required
was present," not "confirmed optional."

**A real, fixed mislabeling bug, found via the live smoke test**:
Greenhouse's custom dropdown/combobox questions (Country, and several
Yes/No questions) render as a labeled visible trigger _plus_ a
separate, unlabeled underlying `<input>` that only holds the selected
value. Before a fix, that second element's proximity fallback picked up
the widget's own generic `"Select..."` prompt text and reported it as
if it were the field's real label. It's now recognized and rejected
(`src/extractors/greenhouse.js`, `GREENHOUSE_GENERIC_PROMPT_TEXT`), and
that synthetic input is honestly reported as `unresolved` instead — its
real question is already correctly captured on the separate, properly
labeled element nearby. On the real Anthropic posting this affects 8 of
31 extracted fields; see `test/fixtures/greenhouse-form.html`'s
dedicated regression case.

**A real, minor, disclosed section-context quirk**: one field
(`Country`) reported `section: "Phone"` on the live smoke test —
`nearestSectionLabel()` finds the nearest _preceding-in-DOM-order_
heading, which does not always match true visual proximity on a page
that uses CSS-based visual reordering. `section` should be read as a
best-effort hint, not a guaranteed-accurate grouping.

**Known, disclosed scope limitation**: a native radio/checkbox group
(e.g. Greenhouse's EEO gender question) is extracted as N separate
fields, one per option, each individually labeled (e.g. `"Male"`,
`"Female"`) with the shared question text only available via `section`
(e.g. `"Gender"`) — there is no synthesized single field carrying
`"Gender"` as its own label with an `options` array the way a
`<select>` is represented. This matches Career Toolkit's Phase 1
`ApplicationQuestion` schema (no `group` column) and the granularity
the investigation's proof of concept already validated; revisit only if
a concrete downstream need for aggregated radio-group questions
emerges.

## Detection method

Primary: the page's own hostname ends in `greenhouse.io` (after
redirects). Secondary/fallback: any `<script src>`/`<link href>` on the
page references a `greenhouse.io` domain — this covers a Greenhouse
application embedded under a company's own custom domain, since
Greenhouse's JS/CSS assets are always served from its own CDN
regardless of the page's own domain (confirmed directly against the
real Anthropic capture, `job-boards.cdn.greenhouse.io/assets/vendor-*.js`).

## Network-boundary assumptions

`src/url-safety.js` rejects, before ever launching a browser: any
scheme other than `http`/`https`; `localhost`/`0.0.0.0`; an IPv4/IPv6
loopback, RFC1918-private, or link-local literal hostname; and, after a
DNS lookup, a hostname that _resolves_ to one of those same ranges.

This is proportionate defense-in-depth, not a complete SSRF platform,
and has one explicit, accepted gap: the DNS-lookup check and the
browser's own subsequent navigation are two separate steps, so a
classic DNS-rebinding attack (the name resolves safely at check time,
then differently by the time Chromium actually connects) is not closed.
Closing that fully would require routing all navigation through an
address-pinning proxy or a network-level egress filter — real
infrastructure, deliberately not built for this pass. Flagged as a
Phase 3 follow-up if this worker is ever pointed at URLs from a less
trusted source than "already-validated `JobPosting.source_url` values a
human chose to capture."

## Docker security model

- Base image: `mcr.microsoft.com/playwright:v1.63.0-noble` (exact
  version match — see "Version pinning rule").
- Runs as the base image's built-in non-root `pwuser` — confirmed
  directly (local build + real AI-box deployment) that Chromium's own
  sandbox works correctly under this user with **no** `--privileged`,
  no `--cap-add=SYS_ADMIN`, no `--no-sandbox` flag, and no host
  networking.
- No Docker socket, no broad host filesystem mounts. The image has no
  writable-volume requirement at all in this pass (no screenshots or
  other diagnostic files are written to disk — see "Diagnostics"
  below).
- No Career Toolkit database credentials, no Career Toolkit API
  credentials, no unrelated API keys — this worker holds no
  credentials of any kind.
- No network service is exposed; the worker has no inbound listening
  port at all. `src/poll.js` only ever makes outbound HTTP requests to
  `CAREER_TOOLKIT_URL` — Career Toolkit never initiates a connection to
  this worker or the AI box it runs on.

## Diagnostics

`diagnostics` in every result is small and structured: timings, page
title, field/warning counts — never a raw DOM dump, accessibility-tree
dump, page text dump, cookies, storage, or secrets.

**No screenshots are captured in this pass.** The disposable
investigation PoC captured a screenshot on every run for exploratory
debugging; this worker deliberately does not, since nothing in Phase 2
consumes one and an unconditional per-run screenshot is exactly the
kind of diagnostic capture the architecture doc's "bounded diagnostics"
policy warns against. If a future pass adds failure-only screenshot
capture, it should write to a worker-local, size-bounded diagnostic
directory (never embed image bytes in the JSON contract) with a
predictable, collision-safe naming scheme and an explicit retention/
cleanup policy — not implemented here because nothing yet needs it.

## Tests

```sh
npm test          # everything under test/ — see below for prerequisites
```

Three kinds:

- **Deterministic, fixture-based** (`test/url-safety.test.js`,
  `test/result-contract.test.js`, `test/greenhouse-extractor.test.js`)
  — no live network dependency. `greenhouse-extractor.test.js` loads
  `test/fixtures/*.html` (synthetic, minimized reproductions of real
  observed Greenhouse structural patterns — never a copy of a real
  scraped page) into a real headless Chromium page via
  `page.setContent()`, so it requires Chromium to be available: already
  true inside the Docker image; locally, run
  `npx playwright install chromium` once first.
- **Runtime/protocol, fixture-server-based** (`test/client.test.js`,
  `test/poll.test.js`) — `client.test.js` starts a real, minimal
  `node:http` server on a random local port to stand in for Career
  Toolkit, so `createClient()`'s actual `fetch()` calls are exercised
  end-to-end without depending on a real Laravel server. `poll.test.js`
  drives `runOnce()`/`deliverResult()`/`runForever()` against a fake
  in-memory client object with an injectable `inspect()` stub, so no
  test here ever launches a real browser or requires the real Laravel
  server — see `src/poll.js`'s `inspectFn` parameter.
- **Live smoke test** (not an automated test file — see "AI-box
  deployment" below) — run explicitly, by hand, against a real public
  Greenhouse posting. Never part of `npm test`, exactly because a live
  third-party page is evidence, not a stable fixture.

No linter or type checker was introduced — five small, plain-ESM JS
files with JSDoc annotations didn't justify the added dependency/build
step for this pass; `node --check` on each file is used as a minimal
syntax gate.

## AI-box deployment / live smoke test

```sh
# from a machine with SSH access to the deployment target:
rsync -az --exclude node_modules workers/browser-inspector/ <host>:<some-clearly-disposable-path>/
ssh <host> "cd <path> && docker build -t browser-inspector:0.1.0 ."

# deterministic tests, inside the real image:
ssh <host> "docker run --rm --entrypoint node -v \$(pwd)/<path>/test:/app/test:ro browser-inspector:0.1.0 --test test/*.test.js"

# one-shot live smoke test (bypasses the protocol; CLI mode only —
# find a real open posting via Greenhouse's own public API, e.g.
#  curl -s 'https://boards-api.greenhouse.io/v1/boards/<company>/jobs' — do not guess a URL):
ssh <host> "docker run --rm --entrypoint node browser-inspector:0.1.0 src/cli.js '<live greenhouse job URL>'"

# production runtime — the poll loop, talking to a real Career Toolkit instance
# reachable from this host on the LAN:
ssh <host> "docker run -d --name browser-inspector --restart unless-stopped \
    -e CAREER_TOOLKIT_URL='http://<career-toolkit-host>:8000' \
    -e BROWSER_WORKER_TOKEN='<shared secret, matches Career Toolkit's .env>' \
    -e BROWSER_WORKER_IDENTITY='ai-box' \
    browser-inspector:0.1.0"

# tail logs / stop:
ssh <host> "docker logs -f browser-inspector"
ssh <host> "docker stop browser-inspector && docker rm browser-inspector"
```

No port is exposed on the container in either mode — the poll loop
only makes outbound requests. No volume needs to persist; remove the
image (`docker rmi browser-inspector:0.1.0`) and the deployment
directory when fully done with a host.
