# Application Inspector

How Career Toolkit's first agentic capability — a read-only browser-based
inspector of external job-application forms — is architected, and what of
that architecture is actually implemented today. Companion to
`docs/domain-model.md`'s "Application", "WorkflowRun", "WorkflowStep",
"AgentRun", and "ApplicationQuestion" sections (the persisted domain
model); this document covers the pipeline, the worker trust boundary, and
the conventions worth keeping stable across future changes — the same
split `docs/job-analysis-generation.md` and its siblings already
establish for the generation pipelines.

**Implementation status: domain foundation only.** As of this document,
`Application`, `WorkflowRun`, `WorkflowStep`, `AgentRun`, and
`ApplicationQuestion` exist as persisted models with relationships and
tests. No browser worker exists yet, no HTTP endpoint accepts a claim or
a result, and nothing in this application triggers an inspection. Every
section below describing the worker, its protocol, or its authentication
is recording an **approved architectural decision for a subsequent
implementation pass**, not current behavior — each such section says so
explicitly.

## Purpose

Career Toolkit's long-term direction is to continuously help operate a
job search while the user remains in control of consequential decisions.
The first concrete step toward that is entirely passive: given an
application URL, open it in a real browser, identify the ATS, extract
the form's fields and questions, and report a structured result — never
filling, never submitting, never inventing an answer. This is
deliberately the least agentic mechanism that could plausibly work,
chosen after a disposable Playwright/Docker proof of concept confirmed
that deterministic DOM/accessibility extraction is sufficient for real
Greenhouse and Lever applications, with no LLM, no vision model, and no
browser-agent framework required for this scope.

## JobPosting vs. Application

`JobPosting` — already implemented, unchanged by this work — is **an
opportunity that exists**: the verbatim source material for a job,
candidate-independent. `Application` is **my mutable operational attempt
to evaluate or pursue that opportunity**. The two are deliberately
different tiers: a `JobPosting` may exist indefinitely with zero
`Application`s against it (most postings a user merely captures and
analyzes are never actually pursued), and — unlike everything else in
this codebase's generation pipeline — an `Application` is not an
immutable snapshot. It has a real, mutable operational lifecycle, because
that is what it actually is: the first entity in this domain that isn't
either canonical data or a frozen AI-generated artifact.

"Draft" is intentionally broad enough to mean "I am actively evaluating
or pursuing this opportunity" — including the act of inspection itself.
Inspection therefore belongs to an `Application`, not to some separate,
un-homed inspection concept: running the Inspector is itself a form of
evaluating the opportunity, so it earns a `Draft` `Application` rather
than requiring a prior, separate "have I decided to apply yet" gate.

## Inspector v1 flow (conceptual)

```
JobPosting (has a source_url)
  -> user requests inspection
  -> Application (Draft) — created or reused if one is already in progress
  -> WorkflowRun (workflow_type: application_inspection)
       -> WorkflowStep (step_key: inspect_application)
            -> AgentRun (agent_type: browser_inspector)
                 -> browser worker claims the run, opens the URL,
                    extracts fields, reports a structured result
       -> ApplicationQuestion rows persisted, in source-form order
  -> WorkflowStep succeeds -> WorkflowRun succeeds
  -> result displayed
```

Nothing in this flow fills or submits anything. A `WorkflowRun` that
cannot make full progress (e.g. an ATS that gates real questions behind
a login wall) is not a technical failure — see "Technical status vs.
inspection outcome" below.

## WorkflowRun

`WorkflowRun` represents **"accomplish this durable business process."**
It is Career Toolkit's own durable execution-state record for a business
process spanning potentially many steps and, eventually, pauses for
human input — the layer that sits _above_ `GenerationAttempt`, not a
replacement for it (see "GenerationAttempt remains specialized" below).
A `WorkflowRun` belongs to exactly one `Application`
(`workflow_runs.application_id`) and has many `WorkflowStep`s. Its
`workflow_type` is a plain, extensible string (`application_inspection`
today; a second workflow type is a one-line addition, never a
migration, following this codebase's established `EvidenceSource`
/`CareerFactType` enum-growth convention).

## WorkflowStep

`WorkflowStep` represents **"perform this bounded step in that
workflow."** A `WorkflowRun` accomplishes its business process by
progressing through one or more `WorkflowStep`s; the Inspector's own
workflow definition has exactly one (`inspect_application`) as of this
document. A `WorkflowStep`'s `step_key` is deliberately a plain string,
not a shared cross-workflow-type enum — a single enum mixing step
vocabulary from unrelated future workflow types would be exactly the
premature-generalization this codebase avoids elsewhere; the right
moment to introduce a real step-key abstraction is when a second
workflow type actually needs one, not before.

## AgentRun

`AgentRun` represents **one execution attempt by an external worker** —
deliberately not "a generic autonomous AI agent." For Inspector v1 the
worker in question is a narrowly-scoped browser extraction process, not
an agent that reasons about what to do next; the name is kept because
the future architecture anticipates other bounded, external,
uncertain-environment execution attempts (a future filling worker, for
instance) sharing this same shape, and because a `WorkflowStep`
represents _intent_ while an `AgentRun` represents one concrete
_attempt_ at satisfying it — the same intent/attempt split
`GenerationAttempt` already embodies for generation work, one layer
down. A `WorkflowStep` may accumulate more than one `AgentRun` over time
(the schema permits it — `agent_runs.workflow_step_id` carries no
uniqueness constraint), but v1's own orchestration never exercises that:
see "Retry semantics" below.

## GenerationAttempt remains specialized

`GenerationAttempt` is untouched by this work and stays exactly what it
already is: a small, standalone durable-execution record for one LLM
generation call (Job Analysis, Job Match, or Resume generation), with
its own proven `queued -> running -> {succeeded|failed}` lifecycle, its
own no-raw-content persistence policy, and its own polymorphic `subject`
serving three real, simultaneous subject types. `WorkflowRun` is a new,
separate concept sitting above it, not a generalization or replacement
of it — a future workflow step that happens to perform an LLM generation
would create a `GenerationAttempt` as that step's own execution record,
exactly as a step might instead create an `AgentRun`; nothing about
`GenerationAttempt` itself needed to change, or has changed, to make
that possible.

## Why WorkflowRun uses `application_id`, not a polymorphic subject

`GenerationAttempt.subject` is a genuine polymorphic `morphTo` because it
has three real, simultaneous subject types today (`JobPosting`,
`JobAnalysis`, `JobMatch`). `WorkflowRun` has exactly one real subject
type right now: `Application`. Introducing polymorphism for a
single-case relation would be pure speculative generality — the same
instinct this codebase already applies elsewhere (`GenerationAttempt`'s
own `result()` is deliberately _not_ a second polymorphic column, for
the identical reason: one real case doesn't justify the abstraction).
`workflow_runs.application_id` is therefore a plain, required, indexed
foreign key. If a second real `WorkflowRun` subject type is ever needed,
that is the moment to migrate to polymorphism — not before.

## Why there is no ApplicationEvent

Everything an `ApplicationEvent` row would record for this slice — "an
inspection started," "an inspection completed" — is already exactly what
a `WorkflowRun`/`WorkflowStep`/`AgentRun`'s own timestamps and statuses
say. A separate event table duplicating that would be pure
denormalization carrying zero new information: an indiscriminate event
log built before anything concrete needs it. `ApplicationEvent` becomes
justified the moment something happens to an `Application` that isn't
already a workflow record — a human manually changing its status, an
external email arriving — and neither exists yet.

## Career Toolkit owns durable state and orchestration

Every piece of state this feature needs to reason about — which
`Application` is being pursued, which `WorkflowRun`/`WorkflowStep` is in
progress, what an `AgentRun` actually found — lives in Career Toolkit's
own database, as the models this document describes. The browser worker
(§ below) is not, and will not become, an orchestrator: it claims one
bounded unit of work, executes it, and reports a result. It never
decides what work exists, never sequences steps, and never holds
durable state of its own beyond what one in-flight browser session
needs.

## Browser worker boundary (approved, not yet implemented)

The following describes the worker's intended trust boundary for the
implementation pass that actually builds it. **None of it exists yet.**

- **Source location**: `workers/browser-inspector/` inside this
  repository — an independently built and independently deployed Docker
  component, not a separate Git repository. One maintainer, one Git
  history, and a protocol that evolves in lockstep with the Laravel side
  outweigh the marginal isolation benefit of a second repository at this
  scale; Docker already provides the real deployment/runtime boundary.
  Should the worker ever need a genuinely independent release lifecycle
  or become reusable outside this project, extracting the directory into
  its own repository remains straightforward.
- **No Career Profile access.** The worker never receives candidate
  data, never queries it, and has no credential that could reach it.
- **No database credentials of any kind**, to Career Toolkit's database
  or otherwise.
- **No submission, filling, or email capability.** v1's worker has no
  `type()`/`select_answer()`/`upload()`/`submit()` primitive at all —
  not merely an instruction not to use one, an absent capability.
- **DOM/ARIA-first extraction**, proven sufficient by the disposable
  proof of concept against real Greenhouse and Lever applications; a
  visual/screenshot fallback is deferred, and adopted only against a
  concrete, demonstrated extraction failure, not preemptively.
- **No browser-local LLM.** Question extraction is fully deterministic;
  any future semantic classification of what a question _means_ happens
  server-side, in Career Toolkit, never inside the worker.
- **No browser-agent framework** (browser-use, Stagehand, Skyvern, or
  similar) — a small, purpose-built Playwright worker was sufficient in
  the proof of concept and would not benefit from a framework that wants
  to own its own task/orchestration loop, which would conflict directly
  with Career Toolkit owning `WorkflowRun`/`WorkflowStep`.
- **No vision/computer-use in v1.**
- **Greenhouse first.** Lever and Workday are explicitly out of scope
  for the first working worker; Workday in particular was found, during
  investigation, to gate real application questions behind mandatory
  account creation for at least one tested tenant — a genuine read-only
  boundary, not an implementation gap to work around.

## Approved future worker transport: HTTP pull -> claim -> result

```
AI-box worker                          Career Toolkit
    │  authenticated poll                   │
    ├───────────────────────────────────────►
    │                              claim pending AgentRun
    │◄───────────────────────────────────────┤
    │  Playwright inspection                 │
    │  authenticated result report           │
    ├───────────────────────────────────────►
    │                       persist result / complete AgentRun+WorkflowStep
```

Chosen over an external queue (no Redis exists anywhere in this stack,
and introducing one solely to reuse Laravel's own queue concept across a
machine boundary was explicitly rejected) and over a direct push to the
worker (which would require an inbound-listening service on the worker
machine, a strictly larger network/firewall surface for no benefit at
this scale). The worker never receives direct database access; Career
Toolkit remains the only writer of its own durable state.

## Approved v1 worker authentication: shared-secret bearer token

A single `BROWSER_WORKER_TOKEN`, compared with constant-time equality in
a narrowly-scoped middleware guarding only the worker's claim/result
endpoints — not Laravel Sanctum. This repository has no auth
infrastructure of any kind today; Sanctum's differentiating value
(multiple scoped tokens, database-backed per-token abilities,
self-service revocation across many identities) isn't exercised by
exactly one static worker identity calling exactly two endpoints. This
is explicitly a v1 decision, not a permanent one — upgrade to
per-worker/scoped credential infrastructure (Sanctum or equivalent) the
moment any of the following becomes real, rather than before:

- more than one worker identity
- more than one worker type
- a remote or otherwise untrusted worker
- materially broader worker capabilities than claim+report
- a need for independent credential revocation/scoping

## Worker presence

Career Toolkit needs to tell the user whether inspection capacity is
actually available, rather than leaving a queued inspection unexplained
if the worker happens to be offline. Piggybacked entirely on the
existing claim-poll protocol rather than a dedicated heartbeat
endpoint: a successful, authenticated `POST /api/worker/agent-runs/claim`
— whether or not it found work — already proves the worker is alive
and reachable, so `App\Http\Controllers\Worker\AgentRunClaimController`
upserts a `WorkerHeartbeat` row (`App\Support\ApplicationInspection\
RecordWorkerHeartbeat`) on every call. No separate protocol traffic, no
Redis, no WebSockets, no distributed presence infrastructure.

`WorkerHeartbeat` is a small, purpose-built table (`identity` unique,
`worker_type`, `last_seen_at`) — a presence record, not a generalized
worker registry — upserted by identity, never accumulated as history.
`App\Support\ApplicationInspection\PresentWorkerAvailability` derives
`online`/`offline` from `last_seen_at` against a threshold computed as
3x `services.browser_worker.poll_interval_seconds` (comfortably
survives one missed/slow poll without flapping): a *config-derived*
number, never a magic constant duplicated independently in the
frontend. `GET /browser-worker/status` exposes this for the nav-bar
badge and any "waiting for browser worker" message; Career Toolkit only
ever *observes* presence here — there is no start/stop/restart action,
and none is planned.

## Retry semantics

A failed inspection is never resumed in place. Retrying always means: a
brand new `WorkflowRun`, a brand new `WorkflowStep`, a brand new
`AgentRun` — exactly mirroring `GenerationAttempt`'s own proven "a retry
is always a new row" convention (see
`docs/job-analysis-generation.md`). Failed `WorkflowRun`s and
`WorkflowStep`s are never reopened. The schema permits more than one
`AgentRun` per `WorkflowStep` (for a possible future bounded
automatic-retry capability within one step, e.g. recovering from a
worker that claimed work and then crashed before reporting), but no
orchestration in this codebase creates a second `AgentRun` under an
already-existing `WorkflowStep` today.

## ApplicationQuestion ordering

`ApplicationQuestion.position` is an explicit, required, zero-based
integer recording the field's position in the source form's own
document order, as the extraction produced it (`0` = first field
encountered, `1` = second, and so on). This is domain information — what
order a human filling out the real form would actually encounter these
questions in — not a database implementation detail, and is therefore
never inferred from insertion order or primary-key order; it must be
populated explicitly by whatever persists an inspection result, taken
directly from the worker's own ordered extraction output.

`ApplicationQuestion.raw_label`, `.required`, and `.options` may all be
`null`. A proof of concept against a real Lever application form found
a genuine case (a 3,302-option university selector) where neither DOM
nor accessibility information formally associated a label with its
control — an honest "unresolved" is an acceptable extraction outcome,
never forced into a guess.

## Technical status vs. inspection outcome

`AgentRun.status` (`queued`/`running`/`succeeded`/`failed`) is purely
about whether the browser execution itself completed without error.
`AgentRun.inspection_outcome` (`complete`/`partial`/
`authentication_required`/`mutation_required`/`unsupported`) — populated
only when `status` is `succeeded` — is a separate, orthogonal
description of what was actually achieved. `status: succeeded` +
`inspection_outcome: authentication_required` means the browser ran
correctly and stopped exactly where it should have: at a login wall it
was never authorized to cross, not a bug. Greenhouse-only v1 will
normally only ever produce `succeeded` + `complete`; the other outcome
values exist now so the schema doesn't need to change the day Lever or
Workday support is added.

## Opportunities inspection state

The Opportunities index/detail (the product-facing label for
`JobPosting` browsing — the model itself is unchanged) shows each
opportunity's inspection state (`not_inspected` / `queued` / `running`
/ `inspected` / `failed` / `unsupported`) without a second status
column on `JobPosting`. `App\Support\ApplicationInspection\
SummarizeInspectionStates` derives it in bulk from the same
`WorkflowRun`/`AgentRun` history `PresentApplicationInspection` already
reads for the Application page — the durable execution history stays
the single source of truth. As with that page, a later failed retry
never hides a previous successful result: `state: inspected` with
`latest_attempt_failed: true` is distinct from a plain `failed`, which
only appears when no attempt has ever succeeded.

## Explicit v1 exclusions

Not part of this or the immediately following implementation pass:
Lever, Workday, or any generic/autonomous ATS handling; `CandidateProfile`
and its EEO/demographic sub-model; `ApplicationPreference`;
`ReusableApplicationAnswer`; `HumanInputRequest`; `ApprovalRequest`;
`ProfileFactCandidate`; `ApplicationSubmission`, `ApplicationResponse`,
and `ApplicationSubmissionAttempt`; application filling, resume/file
upload, or submission of any kind; question normalization or answer
resolution; any LLM integration, local or cloud, inside or outside the
worker; browser-use, Stagehand, Skyvern, or any browser-agent framework;
visual/computer-use capability; a generalized workflow or agent runtime;
WebSockets; Redis; a domain event bus; Laravel Sanctum; PDF-artifact
durability changes.
