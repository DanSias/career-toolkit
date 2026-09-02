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
  "schema_version": "1.0",
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
      "evidence": [
        {
          "excerpt": "Minimum 5 years of backend engineering experience required.",
          "source_section": "Requirements",
          "source_locator": "bullet 2"
        }
      ]
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
      "evidence": [
        {
          "excerpt": "No prior ERP experience is necessary — we'll train on our systems.",
          "source_section": "Nice to Have",
          "source_locator": null
        }
      ]
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
      "evidence": [
        {
          "excerpt": "Must be willing to travel up to 25% of the time.",
          "source_section": "Requirements",
          "source_locator": null
        },
        {
          "excerpt": "This role includes regular travel for client visits.",
          "source_section": "About the Role",
          "source_locator": null
        }
      ]
    }
  ]
}
```

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
- **`evidence`** is always an array, even for a finding with exactly one
  supporting excerpt. Each entry is a close-to-verbatim quote from the
  posting's `description`, plus an optional `source_section` /
  `source_locator` to help a person find it again in the original text.
- Nothing in this contract references a candidate, `CareerFact`, `Skill`,
  or any match/fit concept — see `docs/domain-model.md`'s
  candidate-independence note. A finding describes the job, not how well
  anyone fits it.

## Deliberately out of scope here

- The actual prompt text and provider request/response shapes — see
  `docs/job-analysis-generation.md`.
- Retry, validation-failure, or partial-generation handling — a
  `JobAnalysis` row only ever exists once generation has produced output
  matching this contract; failed/in-progress attempts are not modeled.
