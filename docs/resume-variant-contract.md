# ResumeVariant structured-output contract

This documents the JSON shapes a `ResumeVariant` snapshot is built
from and built against — **this document is the contract, not the
implementation**. See `docs/domain-model.md` "ResumeVariant" for the
persisted domain model these map onto, and
`docs/resume-variant-generation.md` for how a snapshot is actually
generated and persisted (the two-stage pipeline, providers, and
validation).

`ResumeVariant.schema_version` identifies which version of this
contract a given snapshot was generated against. Two independent
prompt versions (`selection_prompt_version`, `wording_prompt_version`)
identify the specific wording of each stage, since a wording-only
revision can target the same schema — see
`docs/resume-variant-generation.md`.

Two stages, two separate contracts, no shared schema:

- **Resume Selection** — structure, evidence, lineage, claim posture.
  No prose.
- **Resume Wording** — prose only, strictly bounded to what Selection
  already approved. No ability to alter citations, evidence, posture,
  chronology, titles, Skills, or Education — those fields simply do
  not exist in this stage's schema.

## Resume Selection

### Candidate input contract

Built by `App\Support\ResumeVariant\ResumeCandidatePayloadBuilder`
from a `CareerProfile`'s live, currently **resume-eligible**
`CareerFact`/`Education`/`Skill` rows (see
`docs/domain-model.md` "ResumeVariant" for the eligibility rule) — the
**full** eligible corpus, not bounded to what a prior `JobMatch` run
happened to cite.

```json
{
  "career_facts": [
    {
      "key": "rocketgate-source-control-gitlab",
      "statement": "RocketGate's engineering source-control and code-review workflow uses GitLab, integrated with Jira for work-item tracking.",
      "fact_type": "bullet",
      "attribution": { "employer": "RocketGate", "role": "Senior Software Engineer", "project": null },
      "role_dates": { "start_year": 2021, "start_month": 3, "end_year": null, "end_month": null },
      "metric": null,
      "skills": [{ "id": 14, "name": "GitLab", "category": "tool" }],
      "job_match_annotations": [
        {
          "job_analysis_finding_id": 482,
          "coverage": "supported",
          "relationship": "direct",
          "requirement_strength": "required",
          "emphasis": "normal"
        }
      ]
    }
  ],
  "education": [
    {
      "id": 3,
      "institution": "Embry-Riddle Aeronautical University",
      "degree": "Bachelor of Science",
      "field_of_study": "Engineering Physics",
      "start_year": null,
      "end_year": 2004
    }
  ],
  "eligible_skills": [
    { "id": 14, "name": "GitLab", "category": "tool" }
  ]
}
```

**Deliberately excluded**: `JobMatchFinding.coverage_rationale`,
`CareerFactMatch.rationale`, `EducationMatch.rationale` — rationale is
internal reviewer commentary, never a factual source for generated
wording, so it never enters either provider payload at all (not just
"is stripped before use"). Also excluded: raw `Evidence`,
`Verification`, and every raw database id for `CareerFact` (referenced
by its stable `key` instead).

`job_match_annotations` (zero or more per fact) is **guidance layered
on top of the fact, never a restriction on what may be selected** — a
`CareerFact` with an empty array here may still be exactly the right
evidence to include; `JobMatch` is annotation, not a recall ceiling.
`eligible_skills` is every canonical `Skill` attached to at least one
eligible `CareerFact` — a bare `Skill` with no supporting fact never
appears here, so anything in this list is fair game for direct
selection.

### Job input contract

Built by the existing `App\Support\JobMatch\JobPayloadBuilder` — the
exact same job-input shape `docs/job-match-contract.md` documents,
reused unchanged. See that document for the full shape.

### Target-terminology input contract

Built by `App\Support\ResumeVariant\TargetTerminologyBuilder`.

```json
[
  {
    "term": "Azure",
    "job_analysis_finding_id": 501,
    "requirement_strength": "required",
    "emphasis": "high",
    "direct_evidence_exists": false
  }
]
```

v1 scope: exactly the `JobAnalysisFinding` rows with
`category = technology`, extracted via two structural paths, in
priority order — never general keyword/entity extraction, and never a
technology ontology/whitelist:

1. **Structured `label`** (primary). Accepted only when the label
   splits into exactly one underscore-delimited token (no qualifier, no
   grouping) AND that token appears as a case-insensitive whole word in
   the finding's own `statement` — the real term is then taken from
   `statement` verbatim (correct casing, e.g. "SQL," "React"), never
   re-cased from the label. A multi-token label
   (`cicd_github_actions_terraform`, `major_cloud_provider`) is always
   rejected outright, even one that happens to name one real
   multi-word technology (e.g. `github_actions`) — nothing short of a
   technology ontology could safely distinguish that case from a
   generic tag-plus-qualifier, and this milestone does not build one.
2. **Atomic `"{Term} is ..."` statement shape** (fallback), tried only
   when the label path yields nothing — preserved for backward
   compatibility with any already-persisted `JobAnalysis` whose
   technology findings happen to use that form.

A finding matching neither path is silently excluded — a deliberate,
documented scope limit. `direct_evidence_exists` is computed once here
by `App\Support\ResumeVariant\DirectEvidenceAuthorization` and
independently re-checked by `ResumeSelectionResponseValidator` at
validation time — never trusted from either computation alone in a way
that skips the other.

### Structured provider response contract

```json
{
  "summary_evidence": ["rocketgate-independently-implemented-from-team-requirements"],
  "skills": [{ "skill_id": 14, "order": 1 }],
  "education_selection": [{ "education_id": 3, "order": 1 }],
  "experience": [
    {
      "role_id": 7,
      "title_choice": "full",
      "bullet_groups": [
        {
          "order": 1,
          "career_fact_keys": ["rocketgate-source-control-gitlab"],
          "job_analysis_finding_ids": [482]
        }
      ]
    }
  ],
  "target_term_usages": [
    {
      "term": "Azure",
      "job_analysis_finding_id": 501,
      "posture": "qualified",
      "location_type": "bullet",
      "role_id": 7,
      "bullet_group_index": 0,
      "relationship_phrase_key": "applicable_to",
      "career_fact_keys": ["rocketgate-source-control-gitlab"]
    }
  ]
}
```

`selected_projects[].project_id` and
`target_term_usages[].role_id`/`bullet_group_index` use a `-1`
sentinel ("location is summary, not bullet", for the latter two) rather
than a nullable field — this codebase's established convention (mirrors
`JobMatchPromptV1::nonEmptyEnum()`), since nullable+enum combinations
are avoided throughout. An Experience `bullet_groups[]` entry has no
`project_id` field at all as of this contract (`schema_version`
`"2.0"`) — see "Field notes" below and
`docs/resume-variant-generation.md` "Design boundary: selection vs.
provenance".

## Field notes — Resume Selection

- **`title_choice`** is a closed key, never title text: `full` (the
  role's exact full canonical `Role.title`) or `segment_1`,
  `segment_2`, ... (that title's exact `"/"`-delimited, trimmed
  segments, left to right) — a title with no `"/"` legally offers only
  `full`. The JSON Schema constrains `title_choice` to the flat, global
  set of tokens any candidate role in this run actually offers (`full`
  plus `segment_1` through the highest segment count present —
  `GenerateResumeVariant::titleChoiceKeyEnum()`), since JSON Schema
  cannot vary an `enum` per array position without tuple/`prefixItems`
  complexity; whether a specific token is legal for the *specific*
  selected role is re-checked independently by
  `ResumeSelectionResponseValidator::assertRoleAndProjectValidity()`
  against a `role_id => {choice_key: title_string}` map computed
  deterministically from `Role.title`
  (`GenerateResumeVariant::titleChoices()`). No fuzzy matching,
  normalization, or fallback to `full` for an unsupported choice — an
  illegal `(role_id, title_choice)` pair is rejected outright. Only
  after validation does `GenerateResumeVariant::toSelectionDraft()`
  resolve the validated key to its real canonical string via that same
  map; the model never supplies, and this contract never accepts, any
  title text directly. This replaced an earlier `display_title: string`
  design (the model reproduced the exact title/segment text itself),
  closed after live evaluation showed that shape schema-loose enough
  to invite drift — see `docs/domain-model.md` "Titles, chronology, and
  attribution".
- **An Experience `bullet_groups[]` entry never declares its own
  project** (`schema_version` `"2.0"`, as of `ResumeSelectionPromptV3`
  — a prior contract version had a `project_id` field here). Every
  `career_fact_key` cited in a bullet group must be *eligible Experience
  evidence* for that entry's declared `role_id` — attributed directly to
  that Role, directly to one of that Role's own Projects, or directly to
  that Role's Employer — re-checked independently against real
  canonical attribution
  (`ResumeSelectionResponseValidator::assertBulletGroupCareerFactsAreEligibleForRole()`).
  A CareerFact attributed directly to the `CareerProfile` is never
  eligible Experience evidence for any Role. Which Project (if any) the
  bullet's evidence actually belongs to is never a model assertion:
  `GenerateResumeVariant` derives it deterministically, after
  validation, from the bullet's own approved `career_fact_keys` and each
  fact's real CareerFact-to-Project attribution — every cited fact
  sharing one non-null project resolves to that project; anything else
  (role-level, Employer-level, or facts spanning more than one project)
  resolves to no specific project. See
  `docs/resume-variant-generation.md` "Design boundary: selection vs.
  provenance" and "Experience CareerFact eligibility".
  `selected_projects[].project_id` is unaffected — that section still
  declares its project explicitly, since it names the entire subject of
  the entry.
- **A bullet group must cite at least one `career_fact_key`.** An empty
  evidence set is a validation failure, not an allowed "structural"
  bullet.
- **Anti-redundancy is structural, not semantic.** Only an *exact*
  duplicate bullet-group evidence set (the same set of
  `career_fact_keys`, order-independent) across two bullet groups is
  rejected. Legitimate evidence reuse — the same `CareerFact` backing
  two different bullets, or a summary claim and a bullet — is always
  allowed; there is no one-fact-per-bullet rule and no semantic-
  similarity/dedup NLP.
- **Content-volume budget — deterministic, provider-neutral (since
  `ResumeSelectionPromptV2`).** Total Experience `bullet_groups` across
  every role combined may never exceed 13 (`MAX_EXPERIENCE_BULLET_GROUPS`);
  `skills` may never exceed 18 selected (`MAX_SELECTED_SKILLS`). Both
  are hard ceilings enforced by `ResumeSelectionResponseValidator`,
  independent of which provider answered and independent of anything
  the prompt itself says — a response exceeding either is rejected
  outright, with no silent truncation of low-ranked entries. `V2`'s
  prompt separately states a *preferred* TARGET range inside each
  ceiling (10-12 bullet groups, 12-16 Skills) as guidance, not a
  validator rule. Chosen by inspecting the actual renderer
  (`resources/views/resume/print.blade.php`: 8.5x11in page, 0.75in
  margins, 11pt/1.45-line-height, no page-count awareness anywhere in
  `App\Support\ResumeDocument`) against a real, already-persisted
  Formic `ResumeVariant` (`resume-selection-v1.5`,
  `openai:gpt-5.6-terra`) that selected 17 Experience bullet groups + 22
  Skills and rendered to roughly three pages against a two-page target
  — notably, one Skill category line alone concatenated 14-15
  individual Skill names into a single ~340-character wrapped line, a
  concrete, measured contributor to the overshoot distinct from bullet
  count. Selected Projects' bullets are deliberately **not** counted
  toward the Experience ceiling — that section is independently
  bounded by its own cap (`ResumeSelectionResponseValidator::MAX_SELECTED_PROJECTS`,
  currently 1, lowered from the original 3 after a follow-up
  investigation measured that the Experience ceiling combined with 2
  Selected Projects still rendered past two pages) and renders far more
  compactly per entry. These numbers are the smallest deterministic
  constraint that would have rejected the over-selection they were each
  chosen from outright while remaining generous enough for legitimate
  tailoring — not a guarantee of an exact rendered page count; PDF
  export's own final acceptance gate (see `docs/resume-variant-generation.md`
  "PDF export") checks the actual rendered artifact for that.
- **`target_term_usages[].posture`** — `direct` | `qualified` |
  `capability` (`App\Enums\ResumeClaimPosture`). `direct` is rejected
  outright unless `direct_evidence_exists` was `true` for that term —
  re-checked independently against
  `DirectEvidenceAuthorization::forFinding()`, never trusted from the
  model's own declaration.
- **`target_term_usages[].relationship_phrase_key`** — one of
  `applicable_to`, `comparable_to`, `closely_related_to`,
  `transferable_to` (`App\Enums\ResumeQualifiedPhrase`), always
  required when `posture = qualified`; must be the `not_applicable`
  sentinel otherwise. There is no "directly transferable to" phrase —
  deliberately excluded as self-contradictory framing.
- **At most one `qualified` usage per bullet/summary location** — a
  bullet or the summary may carry multiple `direct`/`capability`
  usages, but never more than one `qualified` comparison stacked onto
  the same location.
- **`target_term_usages[].career_fact_keys`** must never be empty — a
  claim posture always rests on cited evidence, direct or comparative.
- **`target_term_usages[].career_fact_keys` must be evidence already
  declared at the usage's own `location`** — never borrowed from a
  sibling bullet group, a different role, a Selected Project, or (in
  either direction) the Summary. Enforced by
  `ResumeSelectionResponseValidator::assertUsageEvidenceIsLocal()`,
  independently of (and in addition to) the pre-existing check that
  each key exists somewhere in the supplied corpus at all. See
  `docs/resume-variant-generation.md` "Target-term location integrity"
  for the real historical inconsistency (`ResumeVariant` id 1) that
  motivated this.
- Every id/key referenced anywhere in the response (`career_fact_key`,
  `education_id`, `skill_id`, `role_id`, `project_id`,
  `job_analysis_finding_id`, `term`) is enum-constrained in the JSON
  Schema **and** independently re-checked by deterministic
  application validation — the schema constraint is never trusted
  alone.

## Resume Wording

### Input contract

Built by `App\Support\ResumeVariant\GenerateResumeVariant::buildWordingInput()`
— **exactly** the evidence Selection approved, re-hydrated with real
canonical `CareerFact` text from the already-fetched candidate
payload (never re-queried, never trusted from a Stage-2-declared
string).

```json
{
  "summary_evidence": [{ "key": "...", "statement": "...", "...": "..." }],
  "summary_authorized_skills": ["BigQuery", "Google Analytics", "Salesforce"],
  "summary_direct_target_terms": ["Azure"],
  "experience": [
    {
      "role_id": 7,
      "display_title": "Senior Software Engineer",
      "bullet_groups": [
        {
          "bullet_group_index": 0,
          "career_facts": [{ "key": "...", "statement": "...", "...": "..." }],
          "direct_target_terms": []
        }
      ]
    }
  ]
}
```

`summary_direct_target_terms` and each bullet group's own
`direct_target_terms` carry exactly the `direct`-posture
`target_term_usages` Selection approved at that exact location — see
"Target-term location integrity" in `docs/resume-variant-generation.md`.
Deliberately built from `term`/`location` alone, never from a usage's
own `career_fact_keys` — that would open a second, independent
evidence channel alongside the fact-local `career_facts` already
shown above. Selected Projects never receive this field:
`ResumeTermUsageLocation` has no "project" case, so a target-term
usage can never be located at one.

`summary_authorized_skills` is a different mechanism entirely — see
"Summary authorized-Skills allow-list" in
`docs/resume-variant-generation.md`. It is the closed-world set of
canonical Skill names the Summary's own supplied CareerFacts actually
authorize (attached Skills ∪ Skills recognized in those same facts'
own statement/metric text) — the exact set
`ResumeWordingResponseValidator::assertSkillProvenance()` will check
the generated summary against, computed via that validator's own
`authorizedSkillIds()` (no second definition). It is provider *input*
only, never part of the output JSON schema, and it grants no
authorization the validator doesn't already independently grant — it
exists so the model can execute the pre-existing evidence-locality
rule directly instead of inferring it from prose while seeing every
other location's evidence in the same request. Scoped to the Summary
only in this milestone; Experience bullet groups and Selected Projects
receive no equivalent field.

Alongside this input, a `denylist_terms` array is supplied separately
— every target term from the Selection stage **except** those with an
approved `direct`-posture usage anywhere in this variant. This
predates, and is unrelated to, the per-location `direct_target_terms`
fields above — `denylist_terms` only ever removes a blanket
prohibition variant-wide; `direct_target_terms` is what tells Wording
*where* a term is actually desired, and
`ResumeWordingResponseValidator::assertTargetTermLocationScope()` (see
"Target-term location integrity") is what prevents a `direct` term
from leaking to a location it was never approved for. See also "The
qualified-clause mechanism" below.

### Structured provider response contract

```json
{
  "summary": "Senior engineer with deep experience independently designing and implementing backend systems from ambiguous requirements.",
  "experience": [
    {
      "role_id": 7,
      "bullets": [
        { "bullet_group_index": 0, "text": "Independently implemented the technical solution from team-provided requirements." }
      ]
    }
  ]
}
```

`bullet_group_index` is deliberately **not** enum-constrained per role
in the JSON Schema (the same tuple/`prefixItems` limitation noted for
`title_choice` above — JSON Schema cannot vary an `enum` per array
position) — completeness is enforced entirely by
`ResumeWordingResponseValidator::assertCompleteness()`, which requires
every `(role_id, bullet_group_index)` pair Selection approved to
appear in the response exactly once: no omissions, no duplicates, no
invented pairs.

## Field notes — Resume Wording

- **Never re-declares citations, evidence, posture, chronology,
  titles, Skills, or Education.** These fields simply do not exist in
  this stage's schema — Wording cannot alter lineage even if it tried.
  The orchestrator attaches each bullet's citations post-hoc, by
  `bullet_group_index` array position, from Stage 1's already-approved
  structure.
- **The target-term deny list is a hard boundary, not a suggestion.**
  None of `denylist_terms` (or an obvious variant) may appear anywhere
  in free text — not in a bullet, not in the summary, under any
  framing, including a qualifying/comparative one, including a denial
  ("does not have X"). Enforced by
  `ResumeWordingResponseValidator::assertDenylistRespected()` via a
  case-insensitive substring scan (`mb_stripos`) against every bullet
  and the summary.
- **Guardrails re-checked deterministically, not left to prompt
  instruction alone** — see
  `App\Support\ResumeVariant\ResumeWordingResponseValidator::assertGuardrails()`
  and its own docblock for the exact, named, fixed set of checks: a
  small universal banned-phrase list (`100% accuracy`, `100% success
  rate`, `zero defects`); any single-quoted substring inside a cited
  `Metric.guardrail` string (a generic mechanism that happens to catch
  the Marketing Forecasting "20+ hours/month" guardrail precisely,
  because that guardrail's own text quotes the forbidden phrase — a
  guardrail that doesn't use quotes is not caught this way, a known,
  accepted limitation); "solo"/"single-handedly" wherever a RocketGate
  independent-implementation fact is cited; "GitHub" wherever a
  RocketGate-attributed fact is cited without also citing the distinct
  personal-GitHub fact. Everything else — tone overreach, whether a
  merchant-integration bullet is phrased as sole authorship, whether
  "AI" appears near the Knowledge Exporter description in a misleading
  way — is deliberately left to live/human review, not approximated
  with brittle pattern-guessing that risks rejecting a correct
  sentence. See `docs/resume-variant-generation.md` "Live evaluation".

## The qualified-clause mechanism

A `qualified` target-term usage's exact terminology **never** comes
from Stage 2's free text — Stage 2 cannot write it at all (the
denylist forbids it) and is never asked to. Instead, deterministic
code (`GenerateResumeVariant::appendQualifiedClause()`) appends a
fixed clause to Stage 2's own generated sentence at persistence time:
strips any trailing period, then appends
`", {phrase} {term}."` — e.g. `"...GitLab-based workflows, with
approaches applicable to Azure."` The phrase text itself comes from
`ResumeQualifiedPhrase::render()`, a fixed English-fragment mapping,
never model-generated.

There is no separately declared/validated "actual technologies"
field. The base sentence Stage 2 writes is already grounded in the
real cited `career_fact_keys` for that usage (their statements are the
only evidence in Stage 2's input for that location), so the real
technology being compared is implicit in that sentence's own content,
not restated by the renderer. Validation only guarantees the
denylisted term itself never leaks into free text — it does not
positively verify that some other specific technology name was named
in the base sentence. This is an intentionally weaker positive-content
guarantee than the negative (denylist) one, consistent with this
contract's "structurally deterministic vs. semantically imperfect,
covered by live/human review" split throughout.

## Deliberately out of scope here

- PDF or any other document rendering — this contract covers only the
  two-stage generation pipeline and the persisted relational snapshot
  it produces. Rendering (HTML preview and PDF export) is a separate,
  downstream concern over that same persisted `ResumeVariant`,
  implemented independently of this contract — see
  `docs/resume-variant-generation.md` "PDF export".
- Wording-only regeneration (re-running Stage 2 alone against an
  already-approved Selection) — the schema above supports it (the
  input is already exactly what would be replayed), but v1's
  orchestrator implements full generation only.
- A generalized ATS keyword-extraction system — target terminology is
  scoped exactly as described above, not a general entity extractor.
- Retry, validation-failure, or partial-generation handling — a
  `ResumeVariant` row only ever exists once both stages have produced
  output matching both contracts and one atomic transaction has
  committed the full tree; failed/in-progress attempts are not
  modeled.
