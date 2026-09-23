# JobMatch generation

How a `JobMatch` snapshot actually gets created, as implemented —
companion to `docs/job-match-contract.md` (the semantic contract a
generated response must satisfy) and `docs/domain-model.md`'s "JobMatch"
section (the persisted domain model). This document covers only the
conventions worth keeping stable across future changes; implementation
trivia (exact HTTP client calls, request/response shapes) lives in the
code itself.

## Pipeline

```
(JobAnalysis, CareerProfile)
  -> CandidatePayloadBuilder + JobPayloadBuilder (normalized input payloads)
  -> JobMatchPromptV3 (current — system + user prompt, JSON schema)
  -> GeneratesJobMatch provider (untrusted decoded response)
  -> JobMatchResponseValidator (deterministic — throws on any violation)
  -> JobMatchDraft tree (trusted, readonly value objects)
  -> DB::transaction: JobMatch + JobMatchFinding[] + CareerFactMatch[]/EducationMatch[]
```

Everything up to and including building the trusted draft happens
**before** the database transaction opens. `App\Support\JobMatch\GenerateJobMatch`
is the only place that orchestrates this — see its docblock for the
exact sequence. A failure anywhere in that pipeline (provider,
validation) persists nothing at all; a database-layer failure during the
transaction itself is rolled back by `DB::transaction()`. There is no
`status` column, no generation-attempt table, and no
sealing/finalization step — a `JobMatch` row existing at all already
means generation succeeded end to end. This mirrors
`docs/job-analysis-generation.md`'s pipeline exactly, with one structural
difference worth calling out: unlike `JobAnalysis` generation, this
pipeline **deliberately consumes candidate data** (`CareerFact`,
`Education`, via the two payload builders) — that is its entire purpose,
and `tests/Feature/JobMatch/CandidateDataConsumptionTest.php` guards this
structurally, as the inverse of `JobAnalysis`'s own
candidate-independence guard.

The candidate/job payloads are built **once** and reused for both the
provider call and `JobMatch.input_snapshot` — the exact array sent to the
provider is the exact array frozen into the snapshot, not a
re-derivation of it. `career_fact_key`/`education_id`/
`job_analysis_finding_id` valid sets (used both for the JSON Schema's
`enum` constraints and for `JobMatchResponseValidator`'s independent
referential-integrity check) are derived from these same two payloads via
`array_column()` — one source of truth for "what was this run allowed to
reference," never two separately-maintained lists that could drift apart.

## Provider boundary

`App\Contracts\GeneratesJobMatch` is the entire seam to whichever model
provider answers a request: one method, taking plain strings/arrays
(system prompt, user prompt, JSON schema) and returning
`App\Support\JobMatch\JobMatchProviderResponse` (decoded content plus
`provider`/`model` identity) — the same shape as
`JobAnalysisProviderResponse`, but a distinct type; nothing couples the
two contracts. This is a genuinely separate interface from
`App\Contracts\GeneratesJobAnalysis`, not a reused/generalized one, since
the two represent unrelated generation tasks that happen to share
transport mechanics (see below) — not the same domain concern.

Two providers implement it:
`App\Support\JobMatch\Providers\OllamaJobMatchClient` and
`App\Support\JobMatch\Providers\OpenAIJobMatchClient`, calling OpenAI's
Responses API using `services.openai.key`/`services.openai.model` (the
same configuration keys `JobAnalysis` generation uses).
`AppServiceProvider::resolveJobMatchProvider()` picks the bound
implementation from `services.job_match.provider`
(`AI_JOB_MATCH_PROVIDER`), same pattern and same local-first default
(`'ollama'` when unset/blank) as `resolveJobAnalysisProvider()` — see
`docs/job-analysis-generation.md` "Provider boundary" for the full
default/fallback/boot-safety reasoning, which applies identically here.
Job Match uses its own purpose-specific Ollama model/timeout
(`OLLAMA_JOB_MATCH_MODEL`/`OLLAMA_JOB_MATCH_TIMEOUT_SECONDS`, under
`services.ollama.job_match_model`/`job_match_timeout`) rather than Job
Analysis's `OLLAMA_MODEL`/`OLLAMA_TIMEOUT_SECONDS`, so evaluating a
different local model for one purpose never silently changes the
other. `qwen3.8:27b` is the current validated local model for Job
Match — a configuration default, not an architectural dependency.

**Shared HTTP transport, deliberately not a shared framework.** Writing
`OpenAIJobMatchClient` as a fully standalone class first showed roughly
180 of its 217 lines were identical to `OpenAIJobAnalysisClient` — the
raw Responses API call shape, bounded retry on transient failures
(408/429/500/502/503/504), and parsing the response envelope down to a
decoded structured-content array. That mechanics-only overlap was
extracted into `App\Support\OpenAIResponsesApiClient`
(`call(apiKey, model, systemPrompt, userPrompt, schema, schemaName,
maxOutputTokens, logPrefix): OpenAIResponsesApiResult`, throwing the
shared `OpenAIResponsesApiException` on any transport failure). It is
**not** a base class and **not** a generic multi-provider abstraction —
each concrete client (`OpenAIJobAnalysisClient`, `OpenAIJobMatchClient`)
still owns its own domain exception type
(`JobAnalysisProviderException`/`JobMatchProviderException`), its own
`*ProviderResponse` DTO, and its own schema-name/token-budget choices; a
thin `try { $this->transport->call(...) } catch (OpenAIResponsesApiException) { throw new <DomainException>(...) }`
adapter is the entire body of each client's `generate()` method. This
refactor was verified behavior-preserving by re-running
`OpenAIJobAnalysisClient`'s full pre-existing test suite unchanged after
the extraction (11 provider tests, 73 total JobAnalysis/Jobs tests, all
still passing) — the refactor touched no test expectations, only where
the mechanics live.

`OpenAIJobMatchClient` uses a larger `maxOutputTokens` budget (12,000)
than `OpenAIJobAnalysisClient`'s 8,192: a JobMatch response can contain
up to one `matches`/`education_matches` entry per (finding × candidate
fact/education row) combination the model judges relevant, which scales
with candidate dataset size in a way a JobAnalysis response — bounded by
posting length alone — does not. `OllamaJobMatchClient` requests a
larger budget still (16,000, validated live against qwen3.8:27b/
job-match-v3 on the full 25-finding/77-CareerFact Pearly corpus,
`finish_reason: stop` with 18,115 completion tokens actually used) —
this is a *requested* output budget sent as `max_tokens`, not a
provider-enforced ceiling; Ollama's OpenAI-compat endpoint can and did
exceed it while still finishing cleanly. `OllamaJobMatchClient` never
sends `reasoning_effort` (the model's own default) — live evaluation
found default reasoning materially more evidentially faithful than
`reasoning_effort: none` for Job Match specifically, at the cost of
roughly 50% more wall-clock time; that tradeoff was accepted
deliberately given Job Match's factual-faithfulness requirements.

## Prompt and version semantics

`App\Support\JobMatch\Prompts\JobMatchPromptV3` (current) owns the system
prompt, the per-run user prompt (embedding both the candidate and job
payloads, nothing else), and the JSON Schema passed to the provider.
Versioned **independently** of `JobAnalysis`'s own prompt/schema
versions — both currently report `schema_version: "1.0"`, but this is
coincidence, not coupling; the two version sequences will diverge the
moment either contract changes and nothing enforces them staying in
step.

Each prompt bump is its own immutable class, never an in-place edit to
a prior one — `JobMatchPromptV1` and `JobMatchPromptV2` remain in source
control unchanged, exactly as they read when superseded:

- **`JobMatchPromptV1`** — the original contract.
- **`JobMatchPromptV2`** — clarified `no_evidence` to mean "no evidence
  at all," not "no *meaningful* evidence," closing a live-evaluation gap
  where a cited-but-insufficient finding was rejected by
  `JobMatchResponseValidator` for carrying a citation `no_evidence`
  forbids. Schema unchanged.
- **`JobMatchPromptV3`** — addresses two further live-evaluation gaps V2
  didn't touch: what an attached `skill` establishes and doesn't (see
  `docs/job-match-contract.md` "Field notes"), the fact-local evidence
  boundary (a citation may only draw on the one `CareerFact` it cites,
  never import a detail true of a different fact), and a tighter
  `direct`/`transferable`/`contextual` anchor (the finding's own literal
  statement, not its broader category), plus a relationship/rationale
  self-consistency requirement. Schema unchanged; `JobMatchResponseValidator`
  unchanged — this is a prompt-contract-only revision.

Two independent version identifiers are persisted on every `JobMatch`,
mirroring `JobAnalysis`:

- **`schema_version`** — the version of the structured-output contract
  (matches `docs/job-match-contract.md`). Bump this when a field or enum
  case is added, removed, or renamed.
- **`prompt_version`** — the specific prompt implementation used (e.g.
  `job-match-v1`). Bump this for a wording-only revision that targets the
  *same* schema, in addition to a schema change.

Neither field stores the actual prompt text — the versioned class in
source control is the durable record of what a given version said.
`generated_by` stores `"<provider>:<model>"`, built from the actual model
identifier the provider's response echoes back, not the configured
value. `raw_response` stores the complete, already-validated structured
response.

## Deterministic validation (v1)

`App\Support\JobMatch\JobMatchResponseValidator` is the trust boundary
over provider output — the JSON Schema's `enum` constraints on the
provider side are never trusted alone. Shape rules use Laravel's
`present`+`array` (not `required`+`array`) for `matches`/
`education_matches`, since `required` treats a legitimate empty array as
absent. A custom `after()` closure independently re-checks everything the
schema's `enum` constraints are supposed to guarantee, plus several
things no JSON Schema can express:

- **Completeness** — every supplied `job_analysis_finding_id` appears in
  the response exactly once; no omissions, no duplicates.
- **Referential integrity** — every `career_fact_key` was actually
  present in the supplied candidate payload; every `education_id` was
  actually present in the supplied candidate payload. (Belt-and-braces
  against the schema's own `enum` constraint, not a substitute for it —
  see the JSON Schema's own docblock in `JobMatchPromptV1`.)
- **No duplicate references** — the same `career_fact_key` or
  `education_id` cannot appear twice within one finding's `matches`/
  `education_matches`.
- **Coverage-state possibility** — `no_evidence` and `not_assessable`
  require zero combined `matches`+`education_matches`; `supported` and
  `partial` require at least one.

If even one of these fails anywhere in the response, the **entire**
match is rejected: nothing is filtered, nothing is dropped, and no
`JobMatch` row is created — the same all-or-nothing posture
`docs/job-analysis-generation.md` describes for evidence verification.

## Failure handling

No generation attempt — failed or successful-but-invalid — is ever
persisted. Transport-level failures (timeout, HTTP 429, HTTP 5xx) are
retried a bounded number of times inside the shared
`OpenAIResponsesApiClient` transport; an ordinary 4xx is not retried.
Once a response is in hand, any validation failure rejects the whole
match immediately, with no automatic repair or re-prompt loop. A human
retries by invoking "Generate Match" again from the JobAnalysis page,
which is always a completely fresh attempt — including when a `JobMatch`
already exists for the same `(JobAnalysis, CareerProfile)` pair;
generating again always creates a new, independent snapshot rather than
replacing the old one (no `current_job_match_id`, see
`docs/domain-model.md`).

## Live evaluation

`tests/Llm/JobMatchLiveCorpusTest.php` is opt-in, real-provider,
real-canonical-dataset infrastructure — never part of `php artisan test`,
run explicitly via `vendor/bin/pest tests/Llm`. It reuses the same
five-posting design corpus (`sources/jobs/job-analysis-design-set.md`)
`JobAnalysisLiveCorpusTest.php` parses, generating a real `JobAnalysis`
for a chosen posting and then a real `JobMatch` against the real,
freshly-imported canonical `CareerProfile`. See the file's own docblock
for exactly which three postings were chosen (Pearly, Cardiff, Vista) and
why, and for the hard-vs-soft guardrail-check classification carried
over from this milestone's design revision.

### Accepted first live run (2026-09-02)

`JobMatchPromptV1` and `schema_version: "1.0"` were run once against the
real, configured provider (`gpt-5.6-terra`) and the real, imported
canonical `CareerProfile`, then manually reviewed against every persisted
`JobMatch`/`JobMatchFinding`/`CareerFactMatch`/`EducationMatch` row for
all three postings (Pearly, Cardiff, Vista) — not just the automated
suite's own pass/fail. **Result: accepted. No prompt revision (no
`JobMatchPromptV2`) required.**

- **Scope**: 124 `JobAnalysisFinding` rows evaluated across the three
  postings (28 + 53 + 43); 194 total `CareerFactMatch` + `EducationMatch`
  rows produced (62+1, 74+1, 58+0).
- **Coverage semantics** (`supported`/`partial`/`no_evidence`/
  `not_assessable`) remained meaningfully differentiated across all
  three runs — not collapsing toward one default value — and every
  `not_assessable` finding inspected was genuinely outside the
  authorized career-history/education domain (current location, remote/
  immediate-start availability, travel willingness, personal culture-fit
  preference), never used as a stand-in for "the candidate can't do
  this."
- **Relationship semantics** (`direct`/`transferable`/`contextual`)
  likewise stayed differentiated: `direct` was the plurality in every
  run but `transferable` was used substantially and with real
  product-level discrimination (e.g. BigQuery-only evidence rated
  `transferable`, never `direct`, against a Snowflake requirement);
  `contextual` stayed the smallest category throughout and was reserved
  for genuine background rather than becoming a dumping ground.
- **No hallucination/overstatement found.** A pattern scan across every
  `coverage_rationale` and every `CareerFactMatch`/`EducationMatch`
  `rationale` in all 124 findings for 100%/zero-defects/solo/alone/
  budget-ownership language returned zero hits; manual reading found no
  invented technologies, scale, leadership, or recency.
- **No unsupported candidate-years figure was ever stated.** A regex
  sweep for any `\d+\s*years?` pattern across the same rationale text
  returned zero matches; years-anchored findings were consistently
  reflected through `coverage` (typically `partial`, with hedged
  language) rather than a computed duration.
- **Education matching behaved correctly.** Two `EducationMatch` rows
  were produced, and neither silently upgraded a non-CS degree into
  Computer Science: a "CS or similar quantitative/technical/engineering
  degree" finding correctly rated the candidate's BS Engineering Physics
  `direct` (the finding's own wording explicitly accepts engineering
  degrees, so this is a literal fit, not an analogy); a narrower
  "engineering fundamentals to identify incorrect AI-generated output"
  finding correctly rated the same degree only `contextual`.
- **All eight canonical guardrails held on every citation actually
  produced** — the Toolkit-vs-Remediation number conflation and
  Marketing-Forecasting-vs-superseded hard checks passed on every
  qualifying citation, and manual reading of every soft-flagged
  citation (merchant integration, 43,348-vs-corrected, Nexus $25M+,
  GitLab/GitHub, Knowledge Exporter, independent-implementation-vs-solo)
  found no violation.
- **Two of the eight canonical guardrails were not exercised by this
  particular corpus** — this is a corpus-coverage gap, not a passing or
  failing result, and their underlying deterministic checks in the test
  file remain valid and unchanged:
  - the Transaction Remediation "corrected approximately 32,000+"
    figure (`rocketgate-transaction-remediation-total-corrected`) was
    never cited by any of the three postings' matches;
  - the Marketing Forecasting "10-15 hours/month"
    (`pearson-marketing-budget-forecast-hours-saved-per-month`) fact was
    never cited by any of the three postings' matches.

  If a future revision needs to exercise these live, the corpus would
  need a posting whose findings plausibly pull one of these two specific
  facts in (e.g. a finding about corrected-record-count reporting, or
  about a smaller-scale/departmental forecasting efficiency metric
  specifically, as distinct from Nexus's own reporting-time metrics).
- **Minor calibration notes, recorded as watch items, not blockers:**
  relational/SQL evidence was occasionally rated `transferable` rather
  than `direct` where the candidate directly built on a named SQL
  database (arguably over-conservative, not a hallucination risk since
  it under-claims rather than over-claims); a small number of
  `CareerFactMatch.rationale` values were near-verbatim restatements of
  the cited fact's own `statement` rather than explaining the specific
  connection to the finding at hand. Neither pattern appeared severe or
  frequent enough to warrant a prompt revision on its own — both are
  worth re-checking against a larger body of future live-match data
  before deciding whether `JobMatchPromptV2` is warranted.
- **Token usage** (no dollar cost recorded — no published per-token
  price for `gpt-5.6-terra` exists anywhere in this repo or its config,
  and none is fabricated here): Pearly 21,057 in / 8,337 out; Cardiff
  24,814 in / 13,447 out; Vista 23,523 in / 11,100 out — 69,394 input,
  32,884 output, 102,278 total tokens across the 6 real calls (one
  `JobAnalysis` + one `JobMatch` generation per posting).
