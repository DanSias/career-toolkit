# JobAnalysis structured-output contract

This documents the JSON shape a `JobAnalysis` snapshot is built from —
**this document is the contract, not the implementation**. See
`docs/domain-model.md` "JobAnalysis" for the persisted domain model this
maps onto, and `docs/job-analysis-generation.md` for how a snapshot is
actually generated and persisted (the prompt, provider, and validation
pipeline).

`schema_version` on `JobAnalysis` identifies which version of this
contract a given snapshot was generated against, so the contract can
evolve later without invalidating or silently reinterpreting existing
snapshots.

## Shape

```json
{
  "schema_version": "2.0",
  "role_summary": "A one-to-two paragraph plain-language summary of what this role actually is, independent of any candidate.",
  "overall_seniority": "senior",
  "seniority_rationale": "Posting repeatedly references leading initiatives and mentoring, with no indication of close supervision.",
  "findings": [
    {
      "category": "required_qualification",
      "statement": "5+ years of backend engineering experience",
      "label": "backend_experience_floor",
      "basis": "explicit",
      "requirement_strength": "required",
      "emphasis": "normal",
      "maturity": "unspecified",
      "years_experience_min": 5.0,
      "years_experience_max": null,
      "recency_requirement": null,
      "time_horizon": null,
      "notes": null,
      "evidence_refs": ["S014"]
    },
    {
      "category": "preferred_qualification",
      "statement": "ERP experience is not required for this role",
      "label": "erp_experience",
      "basis": "explicit",
      "requirement_strength": "not_required",
      "emphasis": "normal",
      "maturity": "unspecified",
      "years_experience_min": null,
      "years_experience_max": null,
      "recency_requirement": null,
      "time_horizon": null,
      "notes": "Explicit disclaimer, distinct from the posting simply not mentioning ERP at all.",
      "evidence_refs": ["S029"]
    },
    {
      "category": "travel",
      "statement": "Travel up to 25% required",
      "label": null,
      "basis": "explicit",
      "requirement_strength": "required",
      "emphasis": "high",
      "maturity": "unspecified",
      "years_experience_min": null,
      "years_experience_max": null,
      "recency_requirement": null,
      "time_horizon": null,
      "notes": "Stated twice in the posting.",
      "evidence_refs": ["S015", "S031"]
    }
  ]
}
```

`evidence_refs` values (`"S014"`, `"S029"`, ...) are deterministic source
segment ids — see `docs/job-analysis-generation.md` "Deterministic
evidence (v4)". The provider never returns quoted excerpt text; the
application resolves each id back to its segment's exact source text
before persisting a `JobAnalysisFindingEvidence` row (which still has an
`excerpt` column — that part of the persisted shape is unchanged, only
how it gets populated changed). **Historical note:** `schema_version
"1.0"` (prompt versions v1-v3) used a different, now-superseded evidence
shape — each entry a `{excerpt, source_section, source_locator}` object
with the model directly reproducing quoted text, deterministically
verified against the source afterward. Already-persisted `"1.0"` rows
are unaffected and continue to render correctly; this document describes
the current, `"2.0"` contract only.

## Field notes

- **`overall_seniority`** is always an *inferred* judgment — one of
  `junior`, `mid`, `senior`, `staff_or_above`, `unspecified` — since no
  posting states its own seniority level as a fact the way it might state
  a years-of-experience floor. It is **required in every generated
  analysis and is never `null`**: when the posting gives no real
  seniority signal, the value is the literal string `unspecified`, not
  an absent/null field. (`JobAnalysis.overall_seniority` is a nullable
  database column, but that nullability exists only for flexibility at
  the storage layer — e.g. a future non-generated way to create a
  snapshot — and does not represent an additional state a generation
  produces; every generated analysis populates it.) `seniority_rationale`
  is required alongside it — a short free-text explanation, including
  for `unspecified` (e.g. "posting gives no seniority signal").
- **`emphasis`** is `high`, `normal`, or `low`, and is **required on
  every finding** — there is no null/absent case. `normal` is the
  ordinary, default level: use it whenever nothing about the posting
  makes a finding stand out as unusually stressed (`high`) or
  deliberately de-emphasized (`low`).
- **`maturity`** is `production`, `prototype_or_experimental`, or
  `unspecified`, and is **required on every finding** — there is no
  null/absent case. `unspecified` is the fallback whenever the source
  text does not establish whether a technology/capability finding
  refers to production use versus prototyping/experimentation, or
  whenever the distinction doesn't meaningfully apply to that finding at
  all (most non-technology categories).
- **`category`** must be exactly one of the 13 fixed
  `JobAnalysisFindingCategory` cases: `responsibility`,
  `required_qualification`, `preferred_qualification`, `technology`,
  `capability`, `domain_knowledge`, `success_measure`, `culture_signal`,
  `negative_fit_signal`, `application_request`, `work_arrangement`,
  `travel`, `authorization`. This list is fixed by design — see
  `docs/domain-model.md`.
- **`basis`** is `explicit` (posting states it directly), `strongly_implied`
  (posting doesn't say it outright but leaves little doubt), or `inferred`
  (a reasonable reading, not a direct statement).
- **`requirement_strength`** is `required`, `preferred`, `not_required`, or
  `null`. `null` is not a fourth strength value — it means the category
  doesn't carry a strength at all, or the posting is silent. `not_required`
  is reserved for an explicit disclaimer, never used for silence.
- **`years_experience_min`/`years_experience_max`** are a faithful
  transcription of a stated number or range, never a computed floor or
  ceiling. A floor-only statement ("5+ years") omits `years_experience_max`
  entirely (`null`), it does not invent one.
- **`evidence_refs`** is always an array of 1-2 deterministic source
  segment ids (never a quoted excerpt) — see
  `docs/job-analysis-generation.md` "Deterministic evidence (v4)". Every
  id must be one actually supplied to the model for that generation;
  an invented or nonexistent id fails the entire response.
- Nothing in this contract references a candidate, `CareerFact`, `Skill`,
  or any match/fit concept — see `docs/domain-model.md`'s
  candidate-independence note. A finding describes the job, not how well
  anyone fits it.

## Deliberately out of scope here

- The actual prompt text, provider request/response shapes, and
  deterministic source-segmentation algorithm — see
  `docs/job-analysis-generation.md`.
- Retry/validation-failure/in-progress-attempt handling — a `JobAnalysis`
  row only ever exists once generation has produced output matching
  this contract; a failed or in-progress attempt is tracked separately
  and durably by `GenerationAttempt`, never as a partial or invalid
  `JobAnalysis` row — see `docs/job-analysis-generation.md` "Durable
  generation attempts (foundation)" and "Async Job Analysis".
