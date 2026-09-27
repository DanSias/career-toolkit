# JobAnalysis generation

How a `JobAnalysis` snapshot actually gets created, as implemented —
companion to `docs/job-analysis-contract.md` (the semantic contract a
generated response must satisfy) and `docs/domain-model.md`'s
"JobAnalysis" section (the persisted domain model). This document
covers only the conventions worth keeping stable across future changes;
implementation trivia (exact HTTP client calls, request/response
shapes) lives in the code itself.

## Pipeline

```
JobPosting
  -> SegmentJobPostingDescription (deterministic source segmentation: "S001", "S002", ...)
  -> JobAnalysisPromptV4 (current — system + user prompt over labeled segments, JSON schema)
  -> GeneratesJobAnalysis provider (untrusted decoded response, citing evidence_refs)
  -> JobAnalysisResponseValidator (deterministic — throws on any violation, including an invented/nonexistent evidence_refs id)
  -> resolve each evidence_refs id to its segment's exact source text
  -> JobAnalysisDraft tree (trusted, readonly value objects)
  -> DB::transaction: JobAnalysis + JobAnalysisFinding[] + JobAnalysisFindingEvidence[]
```

Everything up to and including building the trusted draft happens
**before** the database transaction opens. `App\Support\JobAnalysis\GenerateJobAnalysis`
is the only place that orchestrates this — see its docblock for the
exact sequence. A failure anywhere in that pipeline (provider,
validation, or evidence verification) persists nothing at all; a
database-layer failure during the transaction itself is rolled back by
`DB::transaction()`. There is no `status` column, no
generation-attempt table, and no sealing/finalization step — a
`JobAnalysis` row existing at all already means generation succeeded
end to end.

## Provider boundary

`App\Contracts\GeneratesJobAnalysis` is the entire seam to whichever
model provider answers a request: one method, taking plain
strings/arrays (system prompt, user prompt, JSON schema) and returning
`App\Support\JobAnalysis\JobAnalysisProviderResponse` (decoded content
plus `provider`/`model` identity). Nothing about this interface, or
about `App\Support\JobAnalysis\GenerateJobAnalysis`, is aware of
Eloquent, `JobPosting`, or any candidate-side model — a provider
implementation has no path to reach candidate data even by accident.

Two providers implement this:
`App\Support\JobAnalysis\Providers\OllamaJobAnalysisClient` (calling a
local/self-hosted Ollama server's OpenAI-compatible Chat Completions
endpoint) and `App\Support\JobAnalysis\Providers\OpenAIJobAnalysisClient`
(calling OpenAI's Responses API directly via Laravel's HTTP client — no
provider SDK — using OpenAI's native schema-constrained
structured-output mechanism: `text.format` with `type: "json_schema"`,
`strict: true`, not a JSON-mode or forced-function-call workaround; no
temperature/top_p sent). This is deliberately not built as a
multi-provider abstraction: the interface is the only seam, and a
provider (or a fake for tests) is a new class implementing it, not a
change to the orchestrator, prompt, validator, or domain layer.

**career-toolkit is local-first**: `AppServiceProvider::resolveJobAnalysisProvider()`
picks the bound implementation from `services.job_analysis.provider`
(`AI_JOB_ANALYSIS_PROVIDER`), defaulting to `'ollama'` when unset/blank
— the happy path does not require an OpenAI API key. OpenAI remains
fully supported as an explicitly selectable provider
(`AI_JOB_ANALYSIS_PROVIDER=openai`) — a benchmark/reference/manual-
comparison path — but is never an automatic fallback target if Ollama
fails; an unrecognized provider value fails fast rather than silently
choosing either. Resolving the binding only ever constructs a client
object — no inference occurs until something calls `->generate()` on
the result, so this default has no effect on application boot or the
default test suite. Provider/model configuration
(`OPENAI_API_KEY`/`OPENAI_MODEL`, `OLLAMA_BASE_URL`/`OLLAMA_MODEL`/
`OLLAMA_TIMEOUT_SECONDS`) lives in `config/services.php` under
`services.openai`/`services.ollama` — never hardcoded, never sent to
the frontend. `qwen3.8:27b` (the `OLLAMA_MODEL` default) is the
current validated local model for Job Analysis — a configuration
default, not an architectural dependency; nothing in
`OllamaJobAnalysisClient` or the prompt it calls assumes this specific
model, so evaluating a different local model later is a config change,
not a code change.

## Prompt and version semantics

`App\Support\JobAnalysis\Prompts\JobAnalysisPromptV4` (the class
`GenerateJobAnalysis` currently depends on) owns the system prompt, the
per-`JobPosting` user prompt (company/title/location/description only —
nothing else, ever), and the JSON Schema passed to the provider. Each
version is immutable and versioned by class name: a wording or schema
revision becomes a new class (`V2`, `V3`, ...), never an edit to a prior
version in place — `JobAnalysisPromptV1` still exists, unedited, as the
durable record of what `prompt_version: "job-analysis-v1"` meant on any
already-persisted `JobAnalysis` row. This mirrors the "immutable
snapshot" philosophy already applied to `JobAnalysis` itself.

**V1 -> V2** (both target `schema_version: "1.0"` — no schema change):
a live evaluation against the five-posting corpus found `basis` was
`explicit` on 100% of findings (177/177) — the model was treating "there
is verbatim evidence for this" as sufficient for `explicit`, regardless
of how much interpretation the finding's actual proposition required —
and that `requirement_strength: required` was propagating automatically
from a top-level capability heading down into every independently
extracted sub-detail/example finding beneath it, rather than being
judged per finding. V2 recalibrates both: `basis` guidance now
explicitly separates "evidence exists" from "the proposition is
directly stated," with a worked contrast example per value; the
`requirement_strength` guidance is explicit that structural headings are
evidence to weigh, not something to propagate wholesale onto every child
finding. Both are general calibration rules about interpretation depth
and per-finding granularity — deliberately not a formatting-detection
heuristic ("different visual style under a heading means not required"),
which would be brittle and would teach the model to distrust genuine
structural evidence.

**V2 -> V3** (both target `schema_version: "1.0"` — cardinality-only
schema change): a real TRM Labs generation produced 11,972 completion
tokens without finishing (`finish_reason: length`) against an
8,192-token budget. A read-only contract audit found evidence — V2
explicitly instructed "include each occurrence as its own entry" for
every repeated requirement, with no `maxItems` anywhere in the schema —
was the confirmed, unbounded output-amplification mechanism, and that
evidence is never consumed by any downstream generation stage
(`JobPayloadBuilder` excludes it entirely; `TargetTerminologyBuilder`
never reads it). V3 caps evidence at
`JobAnalysisResponseValidator::MAX_EVIDENCE_PER_FINDING` (2) — the
schema's own `evidence.maxItems` reads that same constant, so the
prompt, schema, and validator can never drift apart — and reverses the
per-occurrence instruction: choose the single clearest excerpt, add a
second only for materially different supporting context, never merely
because the posting repeats itself. V3 also adds concision guidance to
`role_summary` and `notes`. `findings` itself remains uncapped
(`minItems: 1`, no `maxItems`) — every materially distinct
responsibility, qualification, technology, capability, domain-knowledge
item, success measure, culture signal, negative-fit signal, application
request, work arrangement, travel item, and authorization item must
still be extracted as its own finding.

**V3 -> V4** (a real `schema_version` bump, `"1.0"` -> `"2.0"` — see
"Deterministic evidence (v4)" below): three real TRM Labs generations
each reached `EvidenceExcerptVerifier` (no truncation, clean decode,
clean schema validation) and were discarded entirely because one
model-retyped "verbatim" evidence excerpt differed from the source by a
handful of characters — a curly apostrophe folded to ASCII in one run,
whitespace inserted mid-word in another. Both were confirmed, via a
full character-level diff against the real stored source, to be
substantively verbatim; the retyping mechanism itself, not the model's
judgment, was the actual point of failure, and each new unanticipated
transformation would have needed its own reactive normalization rule.
V4 removes the retyping step entirely: the model cites deterministic
source segment ids (`evidence_refs: ["S017"]`) instead of reproducing
text, and the application resolves each validated id back to its
segment's exact source text before persisting. `EvidenceExcerptVerifier`
has no remaining role and has been removed.

Two independent version identifiers are persisted on every `JobAnalysis`:

- **`schema_version`** — the version of the structured-output contract
  (matches `docs/job-analysis-contract.md`). Bump this when a field or
  enum case is added, removed, or renamed.
- **`prompt_version`** — the specific prompt implementation used (e.g.
  `job-analysis-v2`). Bump this for a wording-only revision that targets
  the *same* schema, in addition to a schema change (since the prompt
  text always has to be updated to describe a new schema too).

Neither field stores the actual prompt text — the versioned class in
source control is the durable record of what a given version said.
`generated_by` stores `"<provider>:<model>"` (e.g.
`"openai:gpt-5.6-2026-09-01"`), built from the actual model identifier
the provider's response echoes back, not the configured value — so it
reflects what really answered even if configuration drifts later.
`raw_response` stores the complete, already-validated structured
response (the same data used to build the persisted findings/evidence),
never the surrounding HTTP envelope, headers, or token-usage metadata.

## Strict evidence verification (v1-v3, historical)

**Superseded by "Deterministic evidence (v4)" below — kept here only
because `JobAnalysisPromptV1`/`V2`/`V3` are already persisted, and this
is what actually produced and checked their evidence at the time.**
Every `JobAnalysisFindingEvidence.excerpt` a v1-v3 provider response
returned was checked by `App\Support\JobAnalysis\EvidenceExcerptVerifier`
(removed in v4) against the source `JobPosting.description` before
anything was persisted. Comparison normalized Unicode representation
(NFC), folded the typographic single/double quote family to ASCII (see
"Evidence verifier: typographic quote normalization" below), and
collapsed whitespace runs (including line breaks) to a single space —
no case-folding, no dash normalization, no other punctuation
normalization, no fuzzy or edit-distance matching. If even one excerpt
anywhere in the response failed this check, the **entire** analysis was
rejected: nothing filtered, nothing dropped, no `JobAnalysis` row
created. This was the mechanism that ultimately motivated v4 — see
"V3 -> V4" above.

### Evidence verifier: typographic quote normalization (historical)

Added after a real TRM Labs generation (qwen3.8:27b) was rejected at
`findings.29.evidence.0` even though the excerpt was substantively
verbatim: the model reproduced three contractions/possessives ("you're",
"TRM's", "don't") using the ASCII apostrophe (U+0027) while the source
posting used the Unicode typographic apostrophe (U+2019) — every other
character in the 146-character excerpt matched exactly (confirmed via a
full character-by-character diff, logged via the "JobAnalysis evidence
verification failure diagnostic." line — see "Async Job Analysis"
below). `EvidenceExcerptVerifier::normalize()` folded U+2018/U+2019 to
`'` and U+201C/U+201D to `"`, applied identically to both the source
description and the excerpt. This fix, and the class it lived in, no
longer exist as of v4 — a *second*, differently-shaped copy-fidelity
failure (whitespace inserted mid-word) on the very next real run is
what motivated removing the retyping mechanism entirely rather than
adding a second reactive normalization rule.

## Deterministic evidence (v4)

`App\Support\JobAnalysis\SegmentJobPostingDescription` deterministically
splits `JobPosting.description` into small, stably-id'd source segments
("S001", "S002", ...) before every generation — a two-level split (lines,
then sentences within each line) with a small explicit abbreviation
guard (see the class's own docblock), never fuzzy, never NLP-dependent.
Segmentation is pure: given the same (immutable) description, it always
produces the same ordered segment map, so ids are never persisted —
they're recomputed identically at generation time and again when
resolving `evidence_refs`.

`JobAnalysisPromptV4` presents the description to the model as labeled
segments (`[S001] ...`) and asks for `evidence_refs: ["S017"]` per
finding — 1-2 ids, same cardinality as v1-v3's evidence array, still
governed by the same
`App\Support\JobAnalysis\JobAnalysisResponseValidator::MAX_EVIDENCE_PER_FINDING`
(2) constant the schema's `evidence_refs.maxItems` reads directly. The
model never reproduces source text at all. The schema also constrains
`evidence_refs` items to an `enum` of the exact segment ids supplied for
that call, but this is advisory/provider-level protection only — how
strictly a given provider's structured-output decoding enforces a
schema enum isn't something to rely on.
`JobAnalysisResponseValidator`'s own `Rule::in($validSegmentIds)` check
is the sole authoritative, deterministic gate: an invented or
nonexistent id fails the entire response, exactly as any other invalid
enum value does, with a `validation_error` failure category — there is
no longer a distinct "evidence verification" failure category, since
there's nothing left to verify (see "Async Job Analysis" below).

`GenerateJobAnalysis` resolves each already-validated `evidence_refs`
id to its segment's exact, untouched original text before persisting
(`JobAnalysisFindingEvidence.excerpt`); the id itself is stored in
`source_locator` (genuinely meaningful now, unlike the free-text,
never-instructed field v1-v3 left unused), and `source_section` is left
`null` (no v4 equivalent). The persisted evidence is therefore always
character-for-character identical to the source by construction, not
merely verified to be so after the fact — this failure class cannot
recur. `job_analysis_finding_evidence`'s columns are unchanged, so
already-persisted v1-v3 rows keep rendering exactly as before, with no
migration and no frontend change.

Findings remain globally uncapped (`minItems: 1`, no `maxItems`) —
unchanged by v4; every materially distinct responsibility,
qualification, technology, capability, domain-knowledge item, success
measure, culture signal, negative-fit signal, application request, work
arrangement, travel item, and authorization item must still be
extracted as its own finding.

## Failure handling

No generation attempt — failed or successful-but-invalid — is ever
persisted. Transport-level failures (timeout, HTTP 429, HTTP 5xx) are
retried a bounded number of times by the provider client; an ordinary
4xx (bad request, bad credentials) is not retried. Once a response is
in hand, any validation failure (including an invalid `evidence_refs`
id as of v4) rejects the whole analysis immediately, with no automatic
repair or re-prompt loop.
A human retries by invoking "Generate Analysis" again, which is always
a completely fresh attempt.

## Durable generation attempts (foundation)

`App\Models\GenerationAttempt` is a small, standalone persistence
model — schema, enums, subject/result resolution — with no
orchestration behavior of its own. It does not call a provider, run a
prompt, or validate a response; see "Async Job Analysis" below for how
Job Analysis actually uses it as of that section. Job Match and Resume
generation are not wired to it yet — they remain fully synchronous,
exactly as described earlier in this document, until their own
migrations happen.

**Why:** every generation stage's HTTP request blocks synchronously for
the full duration of one or more provider calls (Job Analysis:
minutes; Resume generation's Selection+Wording: potentially longer).
Nothing durable currently exists for an attempt that's in flight, and a
failed attempt leaves no trace beyond a log line — a browser
reload/disconnect loses all visibility into what's happening. A future
queued implementation needs somewhere durable to record `queued` ->
`running` -> `succeeded`/`failed`, independent of any single browser
tab.

**Database queue:** `QUEUE_CONNECTION=database` is already this app's
default (Laravel's stock `jobs`/`job_batches`/`failed_jobs` tables
already exist) — no new queue infrastructure is required to eventually
dispatch a generation stage as a queued job.

**`retry_after` requirement:** `config/queue.php`'s `database`
connection sets `retry_after` to 7200 seconds (`DB_QUEUE_RETRY_AFTER`),
not Laravel's 90-second stock default. A queue's `retry_after` is how
long a worker may hold a job before the queue driver assumes it was
lost (worker crashed) and lets another worker pick it up again — too
low, and a real, still-running Ollama call risks a second worker
starting a duplicate call for the same attempt. 7200s gives comfortable
margin over the worst legitimate case: Resume generation's Selection
then Wording, each up to 900s and each retried up to 3x by
`OllamaChatCompletionsClient` on a transient failure — 2 x (3 x 900 +
2) = 5404s. This is distinct from a queue *worker's* own `--timeout`
flag (enforced by the worker process itself, killing a job that runs
longer than that many seconds) — the existing local dev process bundle
(`php artisan dev`, registering `queue:listen --timeout=0`) already
sets no worker timeout at all, which remains correct and needs no
change; a future persistent production worker (`queue:work`, under
Supervisor/systemd — neither exists yet) must be started the same way,
`--timeout=0` or a value at least as large as 7200, never a smaller
arbitrary default.

**Lifecycle:** a `GenerationAttempt` row will (once wired up) always
move `queued` -> `running` -> exactly one of `succeeded`/`failed`,
never backward, and a retry always creates a new row rather than
reusing or updating a prior one — the same immutable-snapshot
convention `JobAnalysis`/`JobMatch`/`ResumeVariant` already use for
their own successful rows.

**No-raw-content policy:** `generation_attempts` is operational
metadata/history, never another generation-content store. It never
holds a prompt, a raw provider response, job description text,
candidate/profile/resume content, evidence excerpts, or an input
snapshot — only lifecycle timestamps, version identifiers
(`provider`/`model`/`prompt_version`/`schema_version`), the same
non-content token/finish-reason diagnostics already proven safe by
`App\Support\ProviderDiagnostics::toLogContext()`, a short
`failure_category`/`failure_message`, and a pointer (`result_id`) to
the successful domain row it produced. A successful attempt's actual
generated content continues to live only on that domain row
(`raw_response`/`input_snapshot`), exactly as today.

**Subject vs. result:** `subject` (`subject_type`/`subject_id`) is a
genuine polymorphic `morphTo` — a `JobPosting` for a `job_analysis`
attempt, a `JobAnalysis` for `job_match`, a `JobMatch` for
`resume_variant` — registered in `AppServiceProvider::configureMorphMap()`
alongside `CareerFact`'s existing attribution aliases. `result` is
deliberately *not* a second polymorphic relation: `generation_type`
already determines the result's model class 1:1, so a `result_type`
column would only ever duplicate `generation_type` for no benefit;
`GenerationAttempt::result()` is a plain method that resolves
`result_id` against the right model class instead.

## Async Job Analysis

Job Analysis generation is queued — the only stage migrated so far
(Job Match and Resume generation remain fully synchronous, exactly as
described earlier in this document). `JobAnalysisController::store()`
no longer calls `GenerateJobAnalysis` at all; it only creates or looks
up a `GenerationAttempt` and dispatches `App\Jobs\GenerateJobAnalysisJob`,
then redirects back to the JobPosting page immediately — the POST
itself now completes in milliseconds. `App\Jobs\GenerateJobAnalysisJob`
is a thin orchestration wrapper: it calls `GenerateJobAnalysis::generate()`
exactly as the controller used to (that service's own internals are
free to evolve independently, as they did for the v4 evidence
architecture — see "Deterministic evidence (v4)" above) and translates
the outcome onto the attempt.

**Request flow:**

```
POST /jobs/{jobPosting}/analyses
  -> active job_analysis attempt already exists for this posting?
       yes -> do nothing, redirect back (attach to the existing attempt)
       no  -> create GenerationAttempt(status=queued) -> dispatch job -> redirect back
```

```
GenerateJobAnalysisJob::handle()
  -> status=running, started_at=now()
  -> GenerateJobAnalysis::generate($posting)
       success -> status=succeeded, finished_at, result_id, provider/model/prompt_version/schema_version
       JobAnalysisProviderException -> status=failed, failure_category=provider_error, safe diagnostics
       InvalidJobAnalysisResponseException -> status=failed, failure_category=validation_error
         (as of v4: covers every JobAnalysisResponseValidator rejection,
         including an invented/nonexistent evidence_refs id — there is
         no distinct "evidence verification" category anymore, since
         EvidenceExcerptVerifier no longer exists; see "Deterministic
         evidence (v4)" above. The job's own $e->context !== null check
         and logEvidenceVerificationDiagnostic() are now unreachable
         dead code, deliberately left in place rather than deleted —
         harmless, and structurally correct if a context-carrying
         exception shape is ever reintroduced.)
  -> anything else escapes uncaught -> failed() callback -> status=failed, failure_category=unexpected_error
```

**Queue semantics:** `$tries = 1` on the job — `OllamaChatCompletionsClient`
already retries transient transport failures internally (3 attempts),
so an outer Laravel retry on top of that would silently multiply real
provider calls for one logical attempt. The two expected exception
types are caught and translated into a `failed` attempt *without*
rethrowing (the job completes normally from the queue's perspective,
same as the old controller's own catch block never crashing the
request) — this is not swallowing an error, since the outcome is still
durably recorded, just not as a queue infrastructure failure. Anything
genuinely unanticipated is left uncaught so it reaches `failed_jobs`
for visibility; the job's `failed(Throwable $exception)` callback is
what guarantees the attempt can never be left stuck in `running` when
that happens, independent of whichever catch block (if any) actually
ran.

**Duplicate prevention** is application-level only: a check-then-create
query against `GenerationAttempt::active()` for the same subject +
`generation_type`, not a database constraint. For this single-user
local tool that's sufficient — see this document's own "Recommended
future active-attempt concurrency enforcement" reasoning (a partial
unique index is feasible here since this app is SQLite-only, but isn't
worth the added migration/enum coupling for a race that's realistically
a double-click, not concurrent workers). It is explicitly not a
race-proof guarantee: two truly simultaneous requests could both pass
the "no active attempt" check before either creates one.

**Polling:** `GET /generation-attempts/{generationAttempt}` (see
`App\Http\Controllers\GenerationAttemptController`) returns the same
safe `PresentGenerationAttempt` shape used in `JobPostingController`'s
initial page props, so the frontend never has two different shapes for
one concept. No auth/ownership check beyond normal route-model binding
— this app has no login or multi-tenant boundary anywhere (see
`App\Support\CurrentCareerProfile`'s own docblock).

**Frontend:** `resources/js/pages/jobs/show.tsx`'s `GenerateAnalysisAction`
polls every ~2s via `useGenerationAttemptPolling` while the attempt is
queued/running, stops immediately on success/failure, and navigates to
`result_url` on success. `GenerationStatus`/`useElapsedTimer` were
evolved (not replaced) to optionally derive the running-state elapsed
time from a real `started_at` timestamp instead of the moment a button
was clicked — so a page reload mid-generation shows the correct
elapsed time immediately rather than restarting at 0:00. Job Match and
Resume generation's still-synchronous usages of the same component are
unchanged.
