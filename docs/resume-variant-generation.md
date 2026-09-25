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
  -> ResumeSelectionPromptV2 (current — system + user prompt, JSON schema)
  -> GeneratesResumeSelection provider (untrusted decoded response)
  -> ResumeSelectionResponseValidator (deterministic — throws on any violation)
  -> ResumeSelectionDraft (trusted, readonly value objects)
  -> buildWordingInput() (re-hydrates approved fact keys with real canonical text)
  -> ResumeWordingPromptV2 (current — system + user prompt, JSON schema)
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

`App\Support\ResumeVariant\Providers\OpenAIResumeWordingClient` is a
thin adapter over the **same, unchanged** shared transport
`App\Support\OpenAIResponsesApiClient` that
`OpenAIJobAnalysisClient`/`OpenAIJobMatchClient` already use (same
retry-on-transient-failure behavior, same response-envelope parsing;
see `docs/job-match-generation.md` "Provider boundary" for the
extraction history). It owns its own `MAX_OUTPUT_TOKENS` budget
(6,000 — prose only, for a bounded set of already-approved bullets),
its own `schemaName`, and catches the shared
`OpenAIResponsesApiException`, rethrowing as
`App\Exceptions\ResumeGenerationProviderException`. It uses the same
`services.openai.key`/`services.openai.model` configuration keys
`JobAnalysis`/`JobMatch` generation already use.

**Resume Selection is local-first, mirroring Job Match exactly**:
`App\Support\ResumeVariant\Providers\OllamaResumeSelectionClient` and
`OpenAIResumeSelectionClient` both implement `GeneratesResumeSelection`.
`AppServiceProvider::resolveResumeSelectionProvider()` picks the bound
implementation from `services.resume_selection.provider`
(`AI_RESUME_SELECTION_PROVIDER`), defaulting to `'ollama'` when
unset/blank — the happy path does not require an OpenAI API key.
OpenAI remains fully supported as an explicitly selectable provider
(`AI_RESUME_SELECTION_PROVIDER=openai`), never an automatic fallback
target if Ollama fails; an unrecognized value fails fast. Resume
Selection uses its own purpose-specific Ollama model/timeout
(`OLLAMA_RESUME_SELECTION_MODEL`/`OLLAMA_RESUME_SELECTION_TIMEOUT_SECONDS`,
under `services.ollama.resume_selection_model`/`resume_selection_timeout`)
rather than Job Analysis's or Job Match's, so evaluating a different
local model for this purpose never silently changes either of the
other two. `qwen3.8:27b` is the current validated local model for this
purpose too — a configuration default, not an architectural
dependency. `OllamaResumeSelectionClient` requests a 16,000-token
output budget — the same number `OllamaJobMatchClient` uses, arrived at
independently: a first live attempt at 6,000 (sized from historical
OpenAI output size alone) returned `finish_reason: length` against a
real, tokenizer-measured 35,030-token input, so the budget was raised
based on the configured Ollama context (65,536 tokens) instead — see
`OllamaResumeSelectionClient`'s own docblock for the full reasoning.
Like `OllamaJobMatchClient`, it never sends `reasoning_effort` for this
first baseline (the model's own default).

**Resume Wording is local-first on exactly the same pattern**:
`App\Support\ResumeVariant\Providers\OllamaResumeWordingClient` and
`OpenAIResumeWordingClient` both implement `GeneratesResumeWording`,
and `AppServiceProvider::resolveResumeWordingProvider()` picks between
them from `services.resume_wording.provider`
(`AI_RESUME_WORDING_PROVIDER`), defaulting to `'ollama'` when
unset/blank. OpenAI remains fully supported as an explicitly
selectable provider (`AI_RESUME_WORDING_PROVIDER=openai`), never an
automatic fallback target if Ollama fails; an unrecognized value fails
fast. Each stage resolves independently, so Selection on one provider
with Wording on the other is a legal, supported combination. Wording
has its own purpose-specific Ollama model/timeout
(`OLLAMA_RESUME_WORDING_MODEL`/`OLLAMA_RESUME_WORDING_TIMEOUT_SECONDS`,
under `services.ollama.resume_wording_model`/`resume_wording_timeout`,
defaulting to `qwen3.8:27b` and 900 seconds), so evaluating a
different local model here never silently changes any other purpose.
`OllamaResumeWordingClient` requests a 16,000-token output budget —
deliberately not `OpenAIResumeWordingClient`'s 6,000, because Resume
Selection's first local run showed a reasoning-capable local model
exhausts this budget through generation cost rather than final output
size; see that client's own docblock for the measurements behind the
number. It omits `reasoning_effort` by default, exactly as the other
Ollama clients do.

`ResumeWordingPromptV2` (current — version `resume-wording-v2`, schema
version `1.1`), its JSON schema, and `ResumeWordingResponseValidator`
are entirely provider-neutral — the same prompt, schema, and
deterministic checks apply identically to whichever provider answers.

Resolving any of these bindings only ever constructs a client object —
no inference occurs until something calls `->generate()` on the
result, so these defaults have no effect on application boot or the
default test suite.

**Deliberate deviation from the JobMatch precedent**: JobMatch gives
each provider client its own exception type
(`JobAnalysisProviderException`/`JobMatchProviderException`).
`ResumeGenerationProviderException` is instead **shared across all
four provider clients** — `OpenAIResumeSelectionClient`,
`OllamaResumeSelectionClient`, `OpenAIResumeWordingClient`, and
`OllamaResumeWordingClient` — a
deliberate simplification, since every stage/provider combination
fails the same way operationally (a transport failure means "resume
generation failed," full stop; the controller's error handling never
needs to distinguish which stage or provider failed) and a more
granular type would carry no behavioral difference in this milestone.

## Prompt and version semantics

`App\Support\ResumeVariant\Prompts\ResumeSelectionPromptV2` (current)
and `ResumeWordingPromptV2` (current) each own their own system
prompt, per-run user prompt, and JSON Schema — versioned
**independently** of each other and of `JobAnalysis`/`JobMatch`'s own
prompt/schema versions. `ResumeVariant` persists three version
identifiers:

- **`schema_version`** — currently taken from `ResumeSelectionPromptV2::schemaVersion()`
  (`"1.3"`); the two stages' contracts are versioned together as one
  `ResumeVariant` contract version, since Wording's schema is
  structurally simple enough that it has not yet needed independent
  versioning. Revisit this if Wording's contract ever needs to change
  without Selection's.
- **`selection_prompt_version`** / **`wording_prompt_version`** — the
  specific prompt implementation each stage used (`resume-selection-v2.1`
  / `resume-wording-v2`, as of the most recent bump of each). Bump
  the relevant one for a wording-only revision targeting the same
  schema.

`ResumeWordingPromptV1` (`resume-wording-v1.3`) was the first
production Wording prompt and has already reached real, persisted
production use (two real `ResumeVariant` rows). `ResumeWordingPromptV2`
is a new, immutable class rather than an in-place bump of that string,
following the same convention `ResumeSelectionPromptV2` already
established — see that class's own docblock. `resume-wording-v2`
itself has never been persisted, so all three of its semantic changes
were made in place on this same class rather than each requiring a new
one: a clarifying sentence in the `## Summary` section addressing the
cross-location canonical-Skill leakage described under "Skill
provenance" below; a bullet in its metric/guardrail guidance
addressing quantified-figure ambiguity, described under
"Metric-quantity separation" below; and a `## Direct target-term
guidance` section surfacing Selection's approved direct target terms
fact-locally, described under "Target-term location integrity" below.
`schemaVersion()` is unchanged
(`"1.1"`) — the JSON schema is byte-identical to V1.

`ResumeSelectionPromptV1` was bumped in place (`resume-selection-v1` through
`-v1.5`) during its own pre-merge live-evaluation stabilization — a
deliberate, documented exception to this codebase's normal
immutable-versioned-prompt convention, given real `ResumeVariant` rows
already existed under those in-place versions before any of them
reached production. `ResumeSelectionPromptV2` is the first genuinely
independent revision after that milestone, and follows the normal
convention exactly: a new, immutable class, `ResumeSelectionPromptV1`
untouched in source control. It replaces V1's prose-only "select
enough differentiated evidence for a two-page resume" framing (which a
real Formic generation showed insufficient — 17 Experience bullets + 22
Skills, ~3 rendered pages) with an explicit TARGET/HARD MAXIMUM content
budget, the hard half enforced by `ResumeSelectionResponseValidator`
regardless of provider. `schemaVersion()` is unchanged from V1's
current value (`"1.3"`) — the structured response shape did not change,
only prompt text and a validator-only invariant — mirroring exactly how
`JobMatchPromptV2` kept `JobMatchPromptV1`'s `schema_version` unchanged
for its own prompt-text-only revision. See
`docs/resume-variant-contract.md` "Resume Selection" for the full
renderer-evidence writeup behind the specific numbers chosen.

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
`full`), exact-duplicate-bullet-group rejection, a provider-neutral
content-volume budget (total Experience `bullet_groups` ≤ 13, selected
`skills` ≤ 18 — see `docs/resume-variant-contract.md` "Field notes —
Resume Selection"), and the full target-term-usage rule set (posture
authorization, phrase/posture consistency, location/sentinel
consistency, at-most-one-qualified-per-location, and location/evidence
locality — see "Target-term location integrity" below).

**`ResumeWordingResponseValidator`** is the trust boundary for
Stage 2: completeness against exactly what Selection approved (no
missing bullets, no duplicates, no invented ones — computed from
`role_id => [approved bullet_group_index, ...]`, mirroring
`JobMatchResponseValidator`'s own finding-completeness check
generalized to compound keys), the target-term deny list, the named
guardrail checks, location-scoped canonical-Skill provenance (see
"Skill provenance" below), and location-scoped direct target-term
usage (see "Target-term location integrity" below).

If even one rule fails anywhere in a stage's response, that **entire**
stage's response is rejected — nothing is filtered, nothing is
dropped, and no `ResumeVariant` row (or any part of its tree) is ever
created from a partially-valid response, the same all-or-nothing
posture `docs/job-match-generation.md` describes.

## Skill provenance

Added after the first live qwen3.8:27b Resume Wording evaluation
showed a generated summary naming "React" and "Laravel" — technologies
genuinely true of the candidate, and genuinely present elsewhere in
the very same Wording request (attached to other roles'/projects' own
supplied CareerFacts), but not authorized by any of the four facts
actually supplied as summary evidence. Traced precisely: Wording's
single user prompt contains the summary evidence *and* every approved
bullet group's *and* the Selected Project's evidence all at once, so
this was a same-request, different-location leak, not a hallucination
from the model's general training knowledge.

**The guarantee, stated precisely**: if generated prose explicitly
names a recognized canonical Skill, that Skill must be authorized by
the CareerFacts supplied to that exact prose location (summary, one
Experience bullet, or one Selected Project bullet). This is
`ResumeWordingResponseValidator::assertSkillProvenance()`, run at the
same three call sites `assertDenylistRespected()`/`assertGuardrails()`
already run at.

**Authorization is additive and fact-local, not Skill-relation-only**:
a Skill counts as authorized for a location when EITHER (1) a Skill
relation is attached to one of that location's supplied CareerFacts,
OR (2) that same canonical Skill's exact name is recognized (via
`recognizedSkillIds()`) in one of those same facts' own evidence-bearing
text (`statement`, `metric.scope_note`, `metric.guardrail`). Both
sources are positive evidence; neither is a restriction on the other,
and BOTH remain strictly fact-local — a Skill established only by a
*different* CareerFact never counts, whether that fact belongs to
another bullet, another role, another Selected Project, or is simply
uncited at this location. `metric.unit` is deliberately excluded from
the evidence-bearing fields — it's a short value-unit classifier (e.g.
`"percent_reduction"`), not prose evidence, and the Wording prompt
never treats it as such.

This was **not** the original design: the check first shipped as
Skill-relation-only, and a second live evaluation immediately produced
a false positive — a Selected Project bullet naming "AI-assisted
development" exactly as its own supplying CareerFact's `statement`
does, verbatim, but that Skill wasn't attached to the fact. A dedicated
investigation (Skill-provenance authority-model investigation) traced
this to a genuine validator-contract bug, not a canonical-data gap:
`ResumeWordingPromptV1`/`V2`'s own pre-existing rules already
authorize this — the general rule anchors to *"the supplied CareerFact
statements or Metrics for that specific bullet/summary,"* never
mentioning Skills, and the Selected-Project-specific rule explicitly
permits naming a technology *"unless a supplied CareerFact statement
itself names them."* The already-shipped, already-validated
`JobMatchPromptV3` states the identical contract for its own per-fact
evidence boundary: *"use only that fact's own statement, attached
Skills, metric/guardrail/scope_note... Each CareerFact is its own
evidence boundary."* Treating the Skill relation as an exhaustive
restriction contradicted this pre-existing model — `docs/domain-model.md`
"Direct-evidence authorization" already states *"a Skill tag records
topical connection, not proof of hands-on use,"* never that it's
exhaustive. A corpus audit found this gap in 4 of 70 eligible
CareerFacts (5.7%) — a real but minor, pre-existing authoring pattern,
not touched by this fix; canonical data was intentionally left
unchanged.

**Matching semantics — longest exact match wins on overlap**: every
canonical Skill's exact `name` is matched case-sensitively with word
boundaries (`\b`) against the text; when two *different* canonical
Skills' matches overlap in the same text (e.g. "Salesforce," id 23, is
a genuine word-bounded substring of the separate canonical Skill
"Salesforce Marketing Cloud," id 24), only the longer span is kept —
the shorter, overlapping match is suppressed as a sub-match of it. A
separate, non-overlapping occurrence of the shorter name elsewhere in
the same text is unaffected and still recognized on its own (writing
"Salesforce and Salesforce Marketing Cloud" recognizes both). This is
a generic overlap rule (`ResumeWordingResponseValidator::recognizedSkillIds()`),
not a name-specific exclusion — the real canonical catalog was checked
and contains exactly one such overlapping pair today. Equal-length
overlapping ties break on lower Skill id, defensively — the current
catalog cannot produce one (two Skills can't share one profile's exact
`name`, since `name` and `slug` are effectively 1:1 and `slug` is
uniquely constrained per profile).

**This is deliberately NOT**: a general hallucination detector, a
complete technology-provenance validator, or a truthfulness guarantee
of any kind. It explicitly does **not** catch:

- a non-canonical/invented technology name (anything that isn't a real
  Skill record for this profile);
- an alias or paraphrase of a canonical name — most notably "Node" for
  the canonical Skill "Node.js"; matching is a literal, case-sensitive,
  word-bounded match of the exact `name` on record, with no alias
  table (`App\Models\Skill` has no alias field) and no stemming. This
  is unrelated to the overlap rule above: overlap resolution only ever
  suppresses a match between two Skills that BOTH already matched
  exactly — it never creates a match "Node" doesn't otherwise have
  against "Node.js";
- a differently-cased mention of a canonical name (e.g. "react" for
  "React") — matching is case-sensitive on purpose;
- domain-characterization leakage (e.g. a summary saying "payment,
  education, and marketing domains" when only some of that is
  literally stated in its own evidence) — this isn't a Skill mention
  at all;
- a generic unsupported technical claim with no proper-noun Skill
  attached;
- general factual truthfulness of any other kind (ownership tone,
  metric fidelity beyond the existing guardrail-phrase check, causal
  overreach) — all still deliberately left to human review, exactly as
  `ResumeWordingResponseValidator`'s own class docblock already
  describes for its pre-existing checks.

**Verified against the real, already-persisted historical OpenAI
`ResumeVariant` (id 2)** by replaying its `wording_raw_response`
through the refined, additive validator (read-only, zero inference,
the row itself untouched): both the "Salesforce Marketing Cloud"
overlap false positive AND the "AI-assisted development" false
positive are gone — the historical row now passes cleanly. The same
replay technique was applied to the second live qwen3.8:27b response
(already captured in the evaluation transcript, no re-run) — it also
now passes cleanly. Nothing else surfaced in either replay. The
original cross-location leak this check exists to catch (a Summary
naming "React"/"Node.js"/"Laravel" from a *different* location's
facts) remains correctly rejected under the additive contract — the
fix only extends the *evidence source* within one fact's own boundary,
it never widens the boundary itself. See
`ResumeWordingResponseValidator::assertSkillProvenance()`/`authorizedSkillIds()`/`recognizedSkillIds()`'s
own docblocks for the complete, current list of limitations.

Canonical Skill data reaches the validator without a new database
query: `GenerateResumeVariant` already loads every eligible CareerFact
(and their Skills) into `$factsByKey` before Stage 2 runs, and already
builds `$guardrailByFactKey` the same way — `$skillIdsByFactKey` is
one more `->map()` over that same collection, `$canonicalSkillsById`
reuses `$candidatePayload['eligible_skills']` (the same
`ResumeEligibility`-scoped catalog already computed for Stage 1), and
`$textRecognizedSkillIdsByFactKey` is computed once per fact — never
re-scanned per generated location — by running each fact's own
`statement`/`metric.scope_note`/`metric.guardrail` text through the
same `recognizedSkillIds()` method already used to scan generated
prose (no second recognition algorithm). All are just additional
constructor-style parameters to
`ResumeWordingResponseValidator::validate()` — no new abstraction, no
new query, no schema change.

### Summary authorized-Skills allow-list

A deterministic investigation of a recurring qwen3.8:27b Resume
Wording failure — the Summary stochastically naming canonical Skills
(first "React"/"Node.js"/"Laravel", later also "TypeScript") that were
genuinely visible elsewhere in the same request but not authorized by
the Summary's own supplied CareerFacts — found the failure recurring
under byte-identical `ResumeWordingPromptV2` text: 2 of 3 known qwen
runs violated the existing prose rule, one did not. That is stochastic
prompt-only compliance, not a reliable guarantee, even though the
prose rule is explicit and the validator's rejection of every one of
these runs was, in every case, correct — not a false positive.

Rather than restating the prose rule more forcefully again,
`GenerateResumeVariant::buildWordingInput()` now computes a
`summary_authorized_skills` field — an ordered list of canonical Skill
names — and supplies it to Wording alongside `summary_evidence`. It is
computed by calling
`ResumeWordingResponseValidator::authorizedSkillIds()` directly (now
public) with the Summary's own `summaryEvidenceFactKeys`, the same
`$skillIdsByFactKey`/`$textRecognizedSkillIdsByFactKey` Stage 2 already
builds, then resolving each returned Skill id to its canonical name via
the same `$canonicalSkillsById` catalog. **This is deliberately not a
second definition of Skill authorization** — it is the exact
authorization set `assertSkillProvenance()` will check the generated
summary against, computed once and handed to the model as a
generation-time affordance instead of leaving it to infer the same set
from prose while looking at the whole request's evidence at once.

Two things this is **not**:

- **Not a change in what evidence is considered valid.** The
  underlying fact-local, additive authorization rule (attached Skills
  ∪ text-recognized Skills, per Summary CareerFact) is completely
  unchanged — this field only *exposes* that existing rule's result
  more directly. `ResumeWordingResponseValidator` remains the sole,
  fail-closed authority over what generated prose may actually name; a
  name appearing in `summary_authorized_skills` grants no authorization
  of its own that the validator doesn't independently already grant.
- **Not proof that qwen will comply.** This is a model-execution
  reliability aid, not a guarantee — whether it measurably improves
  compliance is an empirical question for a later controlled live
  evaluation, not something this change asserts on its own.

`ResumeWordingPromptV2` (still unpersisted, so refined in place) adds
one new paragraph in `## Summary` explaining `summary_authorized_skills`
as a closed-world list and explicitly resolving a competing pressure
the investigation identified: the prompt's own neighboring
"unmistakably read as a senior full-stack/software candidate"
instruction, combined with summary evidence that doesn't always
include a named full-stack technology, invited exactly this kind of
cross-location borrowing. The new paragraph tells the model to satisfy
that positioning qualitatively (e.g. "full-stack developer," "software
systems," "web applications") instead of reaching for an unauthorized
named technology — generically, naming none of the specific
technologies or the specific candidate/job that motivated it.

Scoped deliberately to the Summary only in this milestone: Experience
bullets and Selected Projects have never been observed to leak a
cross-location Skill, only the Summary (which is uniquely asked to
describe technical/capability *breadth* — the other locations are
explicitly asked to stay narrow to one accomplishment). No analogous
`authorized_skills` field was added to either.

The live-eval harness (`tests/Llm/OllamaResumeWordingFormicLiveTest.php`)
replays a historical `wording_input_snapshot` that predates this field,
exactly the same gap the direct-target-term fix above already
encountered once — it now merges `summary_authorized_skills` in the
same way, and independently re-derives the expected set from a fresh
query against the real database (not by reusing the same computation
twice) as a pre-inference assertion, so a future live run fails before
any call is made if the two ever diverge.

## Metric-quantity separation

A qualitative comparison of two independent live qwen3.8:27b Resume
Wording generations against the same historical OpenAI baseline found
one recurring pattern in both: a guardrail-sensitive quantified figure
(Nexus's $25M+ marketing-spend visibility metric) placed immediately
adjacent to a different quantified claim in the same sentence (an
hours-saved figure). Grammatically correct and not a guardrail
violation on a careful read — the sentence doesn't actually state the
$25M+ was saved — but a real, avoidable fast-read ambiguity about
which figure the sentence is claiming.

`ResumeWordingPromptV2` adds one bullet to its existing
`## Never invent, never compute, never overstate` section addressing
this generically: when a bullet or the summary states more than one
quantified figure, each must stay clearly attached to the single
outcome or scope it actually measures, and no guardrail-sensitive
figure may be phrased close enough to a different figure that it
could plausibly be misread as modifying that other figure's claim.
This is **prompt guidance, not a deterministic validator rule** — it
addresses semantic readability, a concern `ResumeWordingResponseValidator`
was never designed to catch (see that class's own docblock: it
enforces the factual/evidence/guardrail contract precisely, and
leaves tone/clarity/ambiguity to human review, same as every other
non-deterministic concern already documented there). The instruction
deliberately names no specific figure, CareerFact, or example — it
generalizes the pattern rather than special-casing the one real
occurrence that surfaced it.

## Target-term location integrity

`target_term_usages` (see `docs/resume-variant-contract.md`
"Target-terminology input contract") is **location-scoped**, not a
global decision: every usage declares exactly one `location`
(`summary`, or a specific `role_id`+`bullet_group_index`) and cites the
`career_fact_keys` its claim rests on. This section documents two
things added after the target_term_usages → Resume Wording handoff
investigation found the location/evidence data was already present but
never fully trusted or used.

**1. Selection-side integrity.** `ResumeSelectionResponseValidator`
already checked location referential integrity (does this `role_id`/
`bullet_group_index` refer to an actually-declared bullet group) and
evidence referential integrity (does this `career_fact_key` exist
somewhere in the supplied corpus) — but never checked the two
*against* each other. Inspecting the two real, already-persisted
Formic `ResumeVariant` rows found exactly this gap in practice:
`ResumeVariant` id 1's `target_term_usages` cited a `career_fact_key`
that genuinely existed, but belonged to a *different* bullet group
than the one the usage's own `location` declared. Neither existing
check caught it, because neither ever compared the two.
`assertUsageEvidenceIsLocal()` closes this: a usage's own
`career_fact_keys` must be a subset of the evidence already declared
at its own location (the bullet group's own `career_fact_keys` for a
bullet usage, `summary_evidence` for a summary usage) — never
borrowed from a sibling bullet, a different role, a Selected Project,
or (for a bullet usage) the Summary and vice versa. `ResumeVariant`
id 1 remains historically invalid under this rule — it is not
migrated or mutated; this validator governs future generation only.

**2. Direct-term guidance reaches Wording, fact-locally.** Previously,
Wording received no signal at all about which target terms Selection
approved as `direct`-posture claims, or where — the only live effect
was `buildDenylistTerms()` removing the term from `denylist_terms`
*variant-wide*, which let Wording write it anywhere, and in every real
historical case, Wording simply never did. `GenerateResumeVariant::buildDirectTargetTermsByLocation()`
now extracts, from the already-validated Selection result, exactly the
`direct`-posture terms approved at each location, and
`buildWordingInput()` attaches them to that same location's existing
evidence structure: `summary_direct_target_terms` alongside
`summary_evidence`, and `direct_target_terms` inside each bullet
group. `qualified`-posture terms are never included (the term is
appended deterministically after generation — see "Qualified-clause
rendering" below); `capability`-posture terms are never included
either (they remain fully prohibited via the unchanged denylist).
Deliberately does **not** use a usage's own `career_fact_keys` as a
second evidence channel — only `term` and `location` are used; the
evidence a term may be grounded in is whatever that location's own
`buildWordingInput()` entry already supplies, nothing more.

**3. Wording-side leakage prevention.** `buildDenylistTerms()` itself
is unchanged — it still correctly keeps every `capability`/`qualified`
term out of free text everywhere. What was missing is the complementary
check for `direct` terms specifically:
`ResumeWordingResponseValidator::assertTargetTermLocationScope()`
recognizes a `direct` term (via the same `recognizedSkillIds()`
longest-span matching already used for canonical Skills — a term
catalog is just a self-keyed `term => term` map, so this is the same
algorithm, not a second one) and rejects it if it appears anywhere
other than the exact location Selection approved it for. A term that
happens to also be a canonical Skill name must satisfy both checks
independently — approval as a target term never substitutes for Skill
provenance.

**4. Still not a mandatory-emission guarantee.** None of the above
requires an approved `direct` term to actually appear. `direct`
posture remains authorization/encouragement at its location, not an
obligation — `ResumeWordingPromptV2`'s own `## Direct target-term
guidance` section says so explicitly. Whether stronger positive
enforcement (a term must appear somewhere) is ever worth adding is an
open question left for a future milestone with its own evidence, not
decided here.

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
are retried a bounded number of times inside whichever shared
transport the resolved provider uses (`OpenAIResponsesApiClient` or
`OllamaChatCompletionsClient`), same as `JobAnalysis`/`JobMatch`. A
retry is always against the same provider — a failure never reroutes
a stage to the other provider.
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

Both stages have an opt-in live harness under `tests/Llm/`, excluded
from `php artisan test` by `tests/Pest.php`'s `->in('Llm')`
registration and run explicitly, by exact file path only:

- `tests/Llm/OllamaResumeSelectionFormicLiveTest.php` — Stage 1
  against the real, already-persisted Formic `JobMatch` (id 1).
- `tests/Llm/OllamaResumeWordingFormicLiveTest.php` — Stage 2 against
  the approved Selection evidence plan already persisted on
  `ResumeVariant` id 2, so the local model is asked to word *exactly*
  the plan the historical OpenAI run was given.

Both call the transport and the stage's validator directly rather than
`GenerateResumeVariant`, so neither can ever persist a `ResumeVariant`
row; both read the real dev database through a runtime-only
`formic_readonly` connection and never write to it; and neither reads
any job-posting source markdown, only already-imported canonical rows.

Each reports a hard-correctness block (transport metadata,
`finish_reason`, token usage, structured decoding, validator outcome)
and a separate quality block that is **surfaced, never asserted** —
semantic quality is a human-review question. Everything the
deterministic validators deliberately do not check stays in that
human-review half: tone, overreach, metric fidelity, technology
provenance, and the specific mischaracterization risks named in
`ResumeWordingResponseValidator`'s own docblock. The Wording harness
additionally prints each generated sentence paired with the exact
evidence supplied for it, and the historical OpenAI wording for the
same approved plan — a comparison point, explicitly not a ground
truth.

## PDF export

Downstream of everything above: once a `ResumeVariant` is persisted,
`resources/views/resume/print.blade.php` is the single visual source
for both the browser preview (`GET /resume-variants/{id}/preview`) and
PDF export (`GET /resume-variants/{id}/pdf`) — the same Blade markup
renders both, so they can never visually drift. Both routes build a
`ResumeDocument` from the `ResumeVariant` via
`App\Support\ResumeDocument\GenerateResumeDocument` (pure, deterministic,
no provider call, no live Employer/Role/Skill/Education re-resolution
beyond the one documented CareerProfile-contact-info exception) and
pass only that value-object tree to the view.

**Rendering engine: headless Chromium, not dompdf.** An earlier
version of this codebase's comments described dompdf as the intended
future PDF backend, but dompdf never actually rendered or validated
this document — every real pagination/fidelity check this print view
has ever had was performed against Chromium's own print pipeline (see
the "Improve resume print pagination" and "Refine resume typography
and visual hierarchy" commits). PDF export
(`App\Support\ResumeDocument\GenerateResumePdf`) automates that same,
already-observed engine via `chrome-php/chrome` — a pure-PHP client for
Chrome's DevTools Protocol, not a Node/Puppeteer/Playwright dependency:
it launches the local Chrome/Chromium binary directly (auto-discovered,
overridable via the `CHROME_PATH` environment variable), feeds it the
rendered `print.blade.php` HTML in-process via `Page::setHtml()` (never
an HTTP round trip to this app's own preview route), and calls
`Page::pdf()` — the same DevTools `Page.printToPDF` an interactive
"Print to PDF" uses.

**Page geometry — one source of truth.** `print.blade.php` declares
`@page { size: letter; margin: 0; }`, and `GenerateResumePdf` passes
matching explicit options (`paperWidth: 8.5`, `paperHeight: 11`,
`marginTop/Bottom/Left/Right: 0`, `preferCSSPageSize: true`). Both
agree on zero page margin so the `.page` div's own pre-existing 0.75in
padding remains the *only* actual visual margin, never doubled by a
second, independently-configured margin. `displayHeaderFooter: false`
ensures Chromium never injects its own default title/URL/page-number
header-footer. `printBackground: true` preserves the section-heading
accent color and rule.

**PDF page-count regression** (the rendering-layer safety primitive
for dynamic Wording content — see "Metric-quantity separation" above
for the analogous per-bullet concern): `smalot/pdfparser`
(`Smalot\PdfParser\Parser::parseContent()`) parses the generated PDF
bytes and reports `count($document->getPages())` directly — no shelling
out to an external `pdfinfo`-style binary, no custom binary PDF
parsing. `tests/Feature/ResumeDocument/GenerateResumePdfTest.php`
asserts an exact expected page count against the real, human-reviewed
Pearly fixture (`Tests\Support\PearlyResumeVariantFixture`) and fails
the moment that count changes — the first automated tripwire against a
resume silently growing pages as generated content varies. This is
measurement only, not a layout-budget algorithm or a "resumes must be
exactly N pages" rule — no such constraint has been imposed.

**The layout risk this primitive found, and how it was closed.**
Rendering the real, currently-persisted Formic `ResumeVariant` (id 2)
produced 3 pages, with the 3rd page carrying only the tail two
Education entries and otherwise blank — not a CSS defect (every
individual bullet/Education-entry/heading-glue rule fired exactly as
designed), but an inherent consequence of "content flows freely across
pages, no forced page count" applied to a longer resume (17 Experience
bullets + 1 Selected Project) whose natural break point stranded a
small section's tail. A follow-up read-only investigation measured,
using the real renderer, that the existing `MAX_EXPERIENCE_BULLET_GROUPS`
ceiling (13) combined with 2 Selected Projects *still* renders past 2
pages, while combined with at most 1 it does not — see
`ResumeSelectionResponseValidator::MAX_SELECTED_PROJECTS`'s own
docblock. That measurement, not a general layout-budget algorithm, is
why Selected Projects was capped at 1 rather than inventing a shared
numerical Experience/Project budget: the evidence supported a small,
targeted fix, not a bigger one. Selecting 0 remains completely
normal — semantic distinctness/relevance-vs-Experience judgment stays
entirely a model/prompt responsibility; the validator only ever
enforces cardinality (≤1), independent-project provenance, and
citation, never "is this genuinely distinct" semantics, which cannot
be checked deterministically.

**Final artifact-acceptance gate: `App\Support\ResumeDocument\ResumePdfValidator`.**
A deterministic ceiling on the upstream Selection/Wording content
narrows the *likelihood* of overflow but was never claimed to
guarantee an outcome about the actual rendered artifact (see
"measurement only" above) — so PDF export additionally gates on the
real, measured result. `ResumePdfValidator::validate(string $pdfBytes)`
parses the generated bytes with the same `smalot/pdfparser` primitive,
accepts 1-2 pages, and throws `App\Exceptions\ResumePdfPageBudgetExceededException`
(carrying the actual measured page count) above that, or
`App\Exceptions\InvalidResumePdfException` if the bytes cannot be
parsed as a PDF at all — malformed input is never silently treated as
acceptable. Deliberately a separate class from `GenerateResumePdf`
(which only renders) — `ResumeVariantPdfController` orchestrates
generate → validate → respond, mirroring exactly how
`ResumeVariantController::store()` already catches a Selection/Wording
validation failure rather than embedding that policy in the generator
itself.

**On overflow**: the oversized PDF is never streamed/downloaded; the
controller returns a clear failure naming the actual page count and
the 2-page target — never an automatic Selection/Wording regeneration,
never a provider call, never silent content trimming, never a
ResumeVariant mutation. The HTML preview remains available regardless
of whether PDF export would be accepted, since it is a diagnostic
surface, not an export.

**Historical persisted variants are never retroactively validated.**
The `ResumePdfValidator` gate applies only at PDF-export request time,
never to persistence or to already-persisted rows. Formic `ResumeVariant`
id 2 remains valid, unmutated, and fully previewable; requesting its
PDF now correctly fails the gate (3 pages) instead of silently
producing an oversized PDF — the historical over-selection surfaces
itself through the new gate rather than being retroactively fixed or
hidden.

**This exact-Pearly-count regression and the universal ≤2-page gate
serve different purposes, deliberately kept separate**: the Pearly
test in `GenerateResumePdfTest.php` is a renderer/layout regression
tripwire for one specific, known-good fixture (fails if that fixture's
own expected count ever changes, in *either* direction — including
down to 1). `ResumePdfValidator` is a universal runtime rule applied to
any variant's real export request (fails only when a real result
exceeds the target). Neither test collapses into the other, and
neither responsibility lives in the other's class.

**Filename**: `App\Support\ResumeDocument\ResumePdfFilename` builds a
deterministic, sanitized (letters/digits/hyphens only) name from data
already on the pipeline — the owning CareerProfile's name and the
target JobPosting's company, via `ResumeVariant -> JobMatch ->
JobAnalysis -> JobPosting`. No permanent PDF storage: every request
regenerates and streams the PDF fresh.
