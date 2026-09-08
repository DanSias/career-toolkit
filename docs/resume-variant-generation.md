# ResumeVariant generation

How a `ResumeVariant` snapshot actually gets created, as implemented —
companion to `docs/resume-variant-contract.md` (the semantic contract
each stage's response must satisfy) and `docs/domain-model.md`'s
"ResumeVariant" section (the persisted domain model). This document
covers only the conventions worth keeping stable across future
changes; implementation trivia lives in the code itself.

## Pipeline

```
JobMatch
  -> DiscoveryPreflight (optional, deterministic, no provider call)
  -> ResumeCandidatePayloadBuilder + JobPayloadBuilder + TargetTerminologyBuilder
       (full eligible corpus + job findings + target terms, normalized)
  -> ResumeSelectionPromptV1 (system + user prompt, JSON schema)
  -> GeneratesResumeSelection provider (untrusted decoded response)
  -> ResumeSelectionResponseValidator (deterministic — throws on any violation)
  -> ResumeSelectionDraft (trusted, readonly value objects)
  -> buildWordingInput() (re-hydrates approved fact keys with real canonical text)
  -> ResumeWordingPromptV1 (system + user prompt, JSON schema)
  -> GeneratesResumeWording provider (untrusted decoded response)
  -> ResumeWordingResponseValidator (deterministic — throws on any violation)
  -> ResumeWordingDraft (trusted, readonly value objects)
  -> DB::transaction: ResumeVariant + its full relational tree
       (bullets, citations, summary evidence, skills, education, target-term usages + their evidence)
```

`App\Support\ResumeVariant\GenerateResumeVariant::generateFull()` is
the only place that orchestrates this — see its docblock for the
exact sequence. Everything up to and including building both trusted
drafts happens **before** the database transaction opens; a failure
anywhere in that pipeline (either provider call, either validator)
persists nothing at all. This mirrors
`docs/job-match-generation.md`'s pipeline exactly, extended to two
sequential provider calls with a hard trust boundary between them
instead of one.

`DiscoveryPreflight` is entirely optional and stands outside this
pipeline — `GenerateResumeVariant` never calls it (verified
structurally by
`tests/Feature/ResumeVariant/DiscoveryPreflightTest.php`'s
"is skippable" test, which greps the orchestrator's source for the
absence of any reference to it). A human may run it first to see
which likely-missing experiences are worth confirming, but generation
never requires it and never blocks on it. A confirmed "yes" answer to
one of its questions is never wired directly into a `ResumeVariant` —
it must enter the canonical `CareerFact` workflow first, exactly like
any other new evidence. v1 deliberately persists no
discovery-response workflow state at all; it is free to re-run and
has no state to go stale.

## Eligibility is enforced once, upstream of both providers

`App\Support\ResumeVariant\ResumeEligibility` is the single boundary
between canonical data and either provider call — `Visibility::Public`
or `Visibility::Restricted`, excluding `Visibility::Private`, plus the
project-level backstop (a `CareerFact` attributed to a `Project` whose
own `default_visibility` is `Private` is excluded even when the
fact's own visibility is more permissive). `ResumeCandidatePayloadBuilder`
queries through it once; neither provider, neither prompt, and neither
validator re-derives or second-guesses this boundary — a `CareerFact`
simply never appears in either payload if it isn't eligible, so there
is no later step capable of leaking one back in. This is the layer the
visibility-curation review (commit `578caab`) exists to feed correctly
— see `docs/domain-model.md` "ResumeVariant" for the full rule and
`tests/Feature/ResumeVariant/CanonicalVisibilityIntegrationTest.php`
for the integration test running this against the real, imported
canonical dataset.

`JobMatch` is layered on top of each eligible fact as
`job_match_annotations`, never as a recall ceiling — Selection sees
every eligible fact, cited by `JobMatch` or not, and may choose
evidence `JobMatch` never linked to any finding. `JobMatchFinding.coverage_rationale`,
`CareerFactMatch.rationale`, and `EducationMatch.rationale` never
enter either payload at all — not filtered out downstream, simply
never included by `ResumeCandidatePayloadBuilder`/`buildWordingInput()`
in the first place, since neither is a source of fact for generated
wording.

## Provider boundary

`App\Contracts\GeneratesResumeSelection` and
`App\Contracts\GeneratesResumeWording` are two separate, purpose-
specific one-method interfaces — not one generalized "generate
resume content" contract — mirroring how `GeneratesJobMatch` and
`GeneratesJobAnalysis` are kept distinct despite sharing transport
mechanics. Each takes plain strings/arrays (system prompt, user
prompt, JSON schema) and returns its own DTO
(`ResumeSelectionProviderResponse` / `ResumeWordingProviderResponse`,
each carrying decoded content plus `provider`/`model` identity).

This milestone implements both with thin adapters —
`App\Support\ResumeVariant\Providers\OpenAIResumeSelectionClient` and
`OpenAIResumeWordingClient` — over the **same, unchanged** shared
transport `App\Support\OpenAIResponsesApiClient` that
`OpenAIJobAnalysisClient`/`OpenAIJobMatchClient` already use (same
retry-on-transient-failure behavior, same response-envelope parsing;
see `docs/job-match-generation.md` "Provider boundary" for the
extraction history). Reusing it unchanged for a third and fourth
consumer without modification is itself confirmation it was extracted
at the right level of abstraction: mechanics only, no domain
assumptions. Each client still owns its own `MAX_OUTPUT_TOKENS`
budget (Selection 12,000, Wording 6,000 — Selection's response can
include a full experience/skills/education/target-term tree; Wording
produces prose only, for a bounded set of already-approved bullets),
its own `schemaName`, and catches the shared
`OpenAIResponsesApiException`, rethrowing as
`App\Exceptions\ResumeGenerationProviderException`. Both bound to
their contracts in `AppServiceProvider::register()`, same
`services.openai.key`/`services.openai.model` configuration keys
`JobAnalysis`/`JobMatch` generation already use — no separate
Resume-specific model setting was introduced.

**Deliberate deviation from the JobMatch precedent**: JobMatch gives
each provider client its own exception type
(`JobAnalysisProviderException`/`JobMatchProviderException`).
`ResumeGenerationProviderException` is instead **shared by both**
`OpenAIResumeSelectionClient` and `OpenAIResumeWordingClient` — a
deliberate simplification, since both stages fail the same way
operationally (a transport failure means "resume generation failed,"
full stop; the controller's error handling never needs to
distinguish which of the two stages failed) and the extra type would
carry no behavioral difference in this milestone.

## Prompt and version semantics

`App\Support\ResumeVariant\Prompts\ResumeSelectionPromptV1` and
`ResumeWordingPromptV1` each own their own system prompt, per-run user
prompt, and JSON Schema — versioned **independently** of each other and
of `JobAnalysis`/`JobMatch`'s own prompt/schema versions. `ResumeVariant`
persists three version identifiers:

- **`schema_version`** — currently taken from `ResumeSelectionPromptV1::schemaVersion()`
  (`"1.0"`); the two stages' contracts are versioned together as one
  `ResumeVariant` contract version, since Wording's schema is
  structurally simple enough that it has not yet needed independent
  versioning. Revisit this if Wording's contract ever needs to change
  without Selection's.
- **`selection_prompt_version`** / **`wording_prompt_version`** — the
  specific prompt implementation each stage used (`resume-selection-v1`
  / `resume-wording-v1`). Bump the relevant one for a wording-only
  revision targeting the same schema.

`selection_generated_by`/`wording_generated_by` store
`"<provider>:<model>"` from each stage's own response, independently
— two separate calls, potentially independently retried in a future
wording-only-regeneration feature, so collapsing them into one field
would lose which stage actually answered.

## Deterministic validation

Two validators, one per stage, both following
`docs/job-match-generation.md`'s established pattern exactly: Laravel's
`present`+`array` (not `required`+`array`) for arrays that may
legitimately be empty, plus a custom `after()` closure independently
re-checking everything the JSON Schema's `enum` constraints are
supposed to guarantee, plus everything no JSON Schema can express. See
`docs/resume-variant-contract.md` "Field notes" for the complete,
field-by-field list of rules each validator enforces — this section
covers only the structural conventions.

**`ResumeSelectionResponseValidator`** is the trust boundary for
Stage 1: referential integrity (every id/key actually came from the
supplied input), no-duplicate-selection, role/project consistency
(a bullet group's `project_id` really belongs to its `role_id`;
`title_choice` really is one this exact selected role legally offers,
per its own `role_id => {choice_key: title_string}` map — never a
choice merely legal for some other role, and never a fallback to
`full`), exact-duplicate-bullet-group rejection, and the full
target-term-usage rule set (posture authorization, phrase/posture
consistency, location/sentinel consistency, at-most-one-qualified-
per-location).

**`ResumeWordingResponseValidator`** is the trust boundary for
Stage 2: completeness against exactly what Selection approved (no
missing bullets, no duplicates, no invented ones — computed from
`role_id => [approved bullet_group_index, ...]`, mirroring
`JobMatchResponseValidator`'s own finding-completeness check
generalized to compound keys), the target-term deny list, and the
named guardrail checks.

If even one rule fails anywhere in a stage's response, that **entire**
stage's response is rejected — nothing is filtered, nothing is
dropped, and no `ResumeVariant` row (or any part of its tree) is ever
created from a partially-valid response, the same all-or-nothing
posture `docs/job-match-generation.md` describes.

## Persistence

`GenerateResumeVariant::generateFull()` builds the entire relational
tree inside one `DB::transaction()`, after both stages have already
validated successfully — the transaction only ever does trusted,
already-checked work: no provider call, no validation logic, and no
possibility of persisting a partial tree on a mid-transaction failure
(`DB::transaction()`'s own rollback handles that). Every `career_fact_key`/
`education_id`/`skill_id` reference is resolved to a real database id
via a fresh lookup at persistence time (`CareerFact::pluck('id','key')`,
etc.) — never trusted as an id the provider echoed back, since neither
provider schema ever asks for or accepts a raw database id for
`CareerFact` in the first place.

**Two deliberate storage decisions worth calling out:**

- **`display_title` is duplicated per bullet**, not stored once per
  role in a separate table. The read-only review UI must never derive
  it by reading `selection_raw_response` (established convention:
  `raw_response`/`*_raw_response` columns are audit-only, never read
  by application code), so it needs a real relational home; a
  separate roles-within-variant table was judged over-normalization
  for a value this small and this cheap to duplicate. This was a
  gap in the first implementation pass, caught before persistence
  logic was written, not discovered via a failing test.
- **`resume_variant_target_term_usages.bullet_id` is nullable and
  cascades** (the one deliberate cascade among an otherwise all-
  restrictive set of canonical-evidence FKs in this subtree) — a term
  usage has no independent existence apart from the bullet it
  annotates (or the variant itself, for a summary-location usage),
  unlike every other FK in this snapshot's tree that points at *live
  canonical data* and must never silently lose its reference. See
  `docs/domain-model.md` "ResumeVariant" for the full RESTRICT-vs-
  CASCADE rationale across the whole subtree.

## Qualified-clause rendering happens at persistence time, not inside either provider call

See `docs/resume-variant-contract.md` "The qualified-clause
mechanism" for the full rule. Mechanically: for each bullet (and the
summary), `GenerateResumeVariant` looks up whether Selection approved
a `qualified`-posture usage at that exact location, and if so calls
`appendQualifiedClause()` on Stage 2's own generated text for that
location before the `text`/`summary` column is written. Stage 2 never
sees this step happen and never writes the qualifying fragment itself
— the denylist (built by `buildDenylistTerms()`: every target term
*except* those with an approved `direct`-posture usage anywhere in
this variant) guarantees Stage 2 could not have written the term even
if it tried.

## Failure handling

No generation attempt — failed at either stage, or successful-but-
invalid at either stage — is ever persisted. Transport-level failures
are retried a bounded number of times inside the shared
`OpenAIResponsesApiClient` transport, same as `JobAnalysis`/`JobMatch`.
Once a stage's response is in hand, a validation failure rejects the
whole generation attempt immediately — there is no automatic repair,
no re-prompt loop, and Stage 1 succeeding does not create any
persisted record on its own if Stage 2 then fails; nothing is
persisted until both stages have validated and the single transaction
commits. `ResumeVariantController::store()` catches both
`ResumeGenerationProviderException` and
`InvalidResumeVariantResponseException`, surfacing a safe, generic
message under a `resume_generation` session-error key (never the raw
exception message or any internal validator detail) and redirecting
back; a human retries by invoking "Generate Resume" again from the
`JobMatch` page, always a completely fresh attempt — including when a
`ResumeVariant` already exists for the same `JobMatch` (no
`current_resume_variant_id`; generating again always creates a new,
independent snapshot, exactly like `JobMatch` itself).

## Live evaluation

No live-evaluation harness has been built for `ResumeVariant` in this
milestone, and no paid live generation has been run against either
provider. This is a deliberate scope decision, not an oversight:
`docs/job-match-generation.md`'s existing live-corpus infrastructure
(`tests/Llm/JobMatchLiveCorpusTest.php`, opt-in, never part of
`php artisan test`) already establishes the pattern this milestone
would reuse — a real provider call against the real, freshly-imported
canonical `CareerProfile` for a chosen posting from
`sources/jobs/job-analysis-design-set.md`, followed by full manual
review against the guardrail/coverage/relationship checks this
contract's own validators encode as hard checks, plus everything left
to human judgment (tone, overreach, the specific mischaracterization
risks named in `ResumeWordingResponseValidator`'s own docblock:
Knowledge-Exporter-AI-mischaracterization, merchant-integration-
overstatement, Nexus-$25M+-ownership-conflation). Building and running
that harness for `ResumeVariant` was explicitly deferred to a future
milestone with its own explicit approval, per this milestone's own
scope instruction not to run paid live evaluations without it.
