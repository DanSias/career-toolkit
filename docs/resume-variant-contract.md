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
`category = technology` whose `statement` follows the atomic
`"{Term} is ..."` shape (e.g. "Azure is a cloud platform relevant to
the role.") — confirmed against real `JobAnalysis` output. A bundled
finding naming several technologies at once never matches this shape
and is silently excluded; this is a deliberate, documented scope
limit, not general keyword/entity extraction. `direct_evidence_exists`
is computed once here by `App\Support\ResumeVariant\DirectEvidenceAuthorization`
and independently re-checked by `ResumeSelectionResponseValidator` at
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
      "display_title": "Senior Software Engineer",
      "bullet_groups": [
        {
          "project_id": -1,
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

`project_id`, and `target_term_usages[].role_id`/`bullet_group_index`,
use a `-1` sentinel ("no project" / "location is summary, not
bullet") rather than a nullable field — this codebase's established
convention (mirrors `JobMatchPromptV1::nonEmptyEnum()`), since
nullable+enum combinations are avoided throughout.

## Field notes — Resume Selection

- **`display_title`** must be exactly the role's full canonical
  `Role.title` or one of its exact `"/"`-delimited trimmed segments —
  never a rewritten or new string. The JSON Schema leaves this
  loose (`type: string`, no per-role enum — JSON Schema cannot vary an
  `enum` per array position without tuple/`prefixItems` complexity);
  correctness is enforced entirely by
  `ResumeSelectionResponseValidator::assertRoleAndProjectValidity()`
  against a `role_id => [allowed titles]` map computed deterministically
  from `Role.title`.
- **`bullet_groups[].project_id`**, when not `-1`, must belong to the
  same `role_id` as its parent entry — re-checked independently against
  a `project_id => role_id` map built deterministically from live
  `Role`/`Project` data.
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
  "experience": [
    {
      "role_id": 7,
      "display_title": "Senior Software Engineer",
      "bullet_groups": [
        {
          "bullet_group_index": 0,
          "career_facts": [{ "key": "...", "statement": "...", "...": "..." }]
        }
      ]
    }
  ]
}
```

Alongside this input, a `denylist_terms` array is supplied separately
— every target term from the Selection stage **except** those with an
approved `direct`-posture usage anywhere in this variant. See
"The qualified-clause mechanism" below.

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
in the JSON Schema (the same tuple/`prefixItems` limitation as
`display_title` above) — completeness is enforced entirely by
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

- PDF or any other document rendering — v1 produces only the
  persisted relational snapshot and a read-only review page. See
  `docs/resume-variant-generation.md`.
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
