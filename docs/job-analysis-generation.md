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
  -> JobAnalysisPromptV2 (current — system + user prompt, JSON schema)
  -> GeneratesJobAnalysis provider (untrusted decoded response)
  -> JobAnalysisResponseValidator (deterministic — throws on any violation)
  -> EvidenceExcerptVerifier (deterministic — throws on ANY unverifiable excerpt)
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

This milestone implements this with a single provider,
`App\Support\JobAnalysis\Providers\OpenAIJobAnalysisClient`, calling
OpenAI's Responses API directly via Laravel's HTTP client (no provider
SDK) and using OpenAI's native schema-constrained structured-output
mechanism (`text.format` with `type: "json_schema"`, `strict: true`) —
not a JSON-mode or forced-function-call workaround. No
temperature/top_p is sent. This is deliberately not built as a
multi-provider abstraction: the interface is the only seam, and a
second provider (or a fake for tests) is a new class implementing it,
not a change to the orchestrator, prompt, validator, or domain layer.
Provider/model configuration (`OPENAI_API_KEY`, `OPENAI_MODEL`) lives in
`config/services.php` under `services.openai`, bound to the interface in
`AppServiceProvider::register()` — never hardcoded, never sent to the
frontend.

## Prompt and version semantics

`App\Support\JobAnalysis\Prompts\JobAnalysisPromptV2` (the class
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

## Strict evidence verification (v1)

Every `JobAnalysisFindingEvidence.excerpt` a provider returns is checked
by `App\Support\JobAnalysis\EvidenceExcerptVerifier` against the source
`JobPosting.description` before anything is persisted. Comparison
normalizes only Unicode representation (NFC) and collapses whitespace
runs (including line breaks) to a single space — no case-folding, no
punctuation/dash normalization, no fuzzy or edit-distance matching. If
even one excerpt anywhere in the response fails this check, the
**entire** analysis is rejected: nothing is filtered, nothing is
dropped, and no `JobAnalysis` row is created. A response is either
completely trustworthy or not persisted at all. This is intentionally
conservative for v1; if the five-posting live corpus (see
`tests/Llm/JobAnalysisLiveCorpusTest.php`) later demonstrates a genuine
false negative, the normalization rule should be widened deliberately,
from evidence — not loosened preemptively.

## Failure handling

No generation attempt — failed or successful-but-invalid — is ever
persisted. Transport-level failures (timeout, HTTP 429, HTTP 5xx) are
retried a bounded number of times by the provider client; an ordinary
4xx (bad request, bad credentials) is not retried. Once a response is
in hand, any validation or evidence-verification failure rejects the
whole analysis immediately, with no automatic repair or re-prompt loop.
A human retries by invoking "Generate Analysis" again, which is always
a completely fresh attempt.
