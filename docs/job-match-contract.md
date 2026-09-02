# JobMatch structured-output contract

This documents the JSON shapes a `JobMatch` snapshot is built from and
built against — **this document is the contract, not the
implementation**. See `docs/domain-model.md` "JobMatch" for the
persisted domain model these map onto, and `docs/job-match-generation.md`
for how a snapshot is actually generated and persisted (the prompt,
provider, and validation pipeline).

`schema_version` on `JobMatch` identifies which version of this contract
a given snapshot was generated against, so the contract can evolve later
without invalidating or silently reinterpreting existing snapshots.
Independent of `JobAnalysis.schema_version` — same-looking version string
(`"1.0"`), unrelated contract.

There are three shapes involved: the **candidate input**, the **job
input** (together frozen verbatim into `JobMatch.input_snapshot`), and
the **structured provider response** (persisted, once validated, into
`JobMatch.raw_response`).

## Candidate input contract

Built by `App\Support\JobMatch\CandidatePayloadBuilder` from a
`CareerProfile`'s live `CareerFact` and `Education` rows.

```json
{
  "career_facts": [
    {
      "key": "rocketgate-source-control-gitlab",
      "statement": "RocketGate's engineering source-control and code-review workflow uses GitLab, integrated with Jira for work-item tracking.",
      "fact_type": "bullet",
      "attribution": {
        "employer": "RocketGate",
        "role": "Senior Software Engineer",
        "project": null
      },
      "role_dates": {
        "start_year": 2021,
        "start_month": 3,
        "end_year": null,
        "end_month": null
      },
      "metric": null,
      "skills": [
        { "name": "GitLab", "category": "tool" }
      ]
    },
    {
      "key": "pearson-marketing-budget-forecast-hours-saved-per-month",
      "statement": "Saved approximately 10-15 hours per month for internal teams by replacing manual spreadsheet-based budget planning with the Marketing Budget & Forecast Hub.",
      "fact_type": "metric",
      "attribution": { "employer": "Pearson", "role": "...", "project": "Marketing Budget & Forecast Hub" },
      "role_dates": { "start_year": 2019, "start_month": 6, "end_year": 2021, "end_month": 2 },
      "metric": {
        "value": 10.0,
        "value_max": 15.0,
        "unit": "hours_per_month",
        "comparator": "range",
        "scope_note": "Bounded range — no single exact value within 10-15 is confirmed; do not narrow to a specific number.",
        "guardrail": "Do not restate as '20+ hours/month' (the superseded figure) or as the Nexus platform's separate, distinct 20+ hours/week metric."
      },
      "skills": []
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
  ]
}
```

**Deliberately excluded**: raw `Evidence` (including any superseded
quoted wording), `Verification`, and every raw database id for
`CareerFact` (referenced by its stable `key` instead — `Education` has
no natural key, so it's referenced by database id, since `Education` is
never externally addressed the way `CareerFact` is elsewhere in this
app). `role_dates` is `null` whenever the fact's attribution chain has no
`Role` in it at all (a profile-level fact); `metric.guardrail`/
`scope_note`, when present, are hard constraints the matcher must respect
— see "Do not invent anything" in `docs/job-match-generation.md`.

## Job input contract

Built by `App\Support\JobMatch\JobPayloadBuilder` from a stored
`JobAnalysis`.

```json
{
  "role_summary": "A senior backend-leaning full-stack role...",
  "overall_seniority": "senior",
  "seniority_rationale": "Posting repeatedly references leading initiatives with no indication of close supervision.",
  "findings": [
    {
      "id": 482,
      "category": "required_qualification",
      "statement": "Computer Science or similar quantitative/technical/engineering degree, or equivalent practical experience.",
      "label": "cs_degree_or_equivalent",
      "basis": "explicit",
      "requirement_strength": "required",
      "emphasis": "normal",
      "maturity": "unspecified",
      "years_experience_min": null,
      "years_experience_max": null,
      "recency_requirement": null,
      "time_horizon": null,
      "notes": null
    }
  ]
}
```

`id` is the real `JobAnalysisFinding.id` — the same value the response
must reference back via `job_analysis_finding_id`. **Deliberately
excluded**: `JobAnalysisFindingEvidence` excerpts — the matcher reasons
from the finding's own normalized fields, not the posting's raw quoted
text.

## Structured provider response contract

```json
{
  "findings": [
    {
      "job_analysis_finding_id": 482,
      "coverage": "partial",
      "coverage_rationale": "Candidate holds a BS in Engineering Physics, not Computer Science — quantitative/technical and directly relevant to software engineering, but not the same field.",
      "matches": [
        {
          "career_fact_key": null,
          "relationship": null,
          "rationale": null
        }
      ],
      "education_matches": [
        {
          "education_id": 3,
          "relationship": "transferable",
          "rationale": "Engineering Physics is a quantitative/technical degree that plausibly satisfies 'or similar', though it is not Computer Science itself."
        }
      ]
    },
    {
      "job_analysis_finding_id": 501,
      "coverage": "not_assessable",
      "coverage_rationale": "Requires being based in one of three named cities — current physical location is outside what career-history evidence can establish.",
      "matches": [],
      "education_matches": []
    }
  ]
}
```

(The `matches` entry with all-`null` fields above is illustrative
formatting only — a real response never emits a null-valued match; an
empty `matches: []` array is used instead when no `CareerFact` applies.)

## Field notes

- **`coverage`** is exactly one of `supported`, `partial`, `no_evidence`,
  `not_assessable` — see `docs/domain-model.md` "JobMatch" for the full
  epistemic distinction between `no_evidence` (nothing in *this dataset*
  addresses the finding) and `not_assessable` (the finding is outside
  this matcher's authorized domain entirely, regardless of dataset
  content). Neither is ever a claim that the candidate lacks a
  capability. There is no `contradicted` case — see "Deliberately out of
  scope here" below.
- **`matches` and `education_matches` are always both present as arrays**
  (possibly empty), never omitted — including for `not_assessable`
  findings, where both **must** be empty; a non-empty array on a
  `not_assessable` finding is a validation failure (see
  `docs/job-match-generation.md`).
- **`career_fact_key`** must be exactly one of the keys present in the
  candidate input's `career_facts[].key` for this run. **`education_id`**
  must be exactly one of the ids present in the candidate input's
  `education[].id`. **`job_analysis_finding_id`** must be exactly one of
  the ids present in the job input's `findings[].id`. All three are
  enum-constrained in the JSON Schema sent to the provider (strict
  structured outputs) **and** independently re-checked by deterministic
  application validation — the schema constraint is never trusted alone.
- **`relationship`** (on each `matches`/`education_matches` entry) is
  exactly one of `direct`, `transferable`, `contextual` — categorical,
  not a quality ranking. See `docs/domain-model.md` "JobMatch" for why
  this is categorical rather than ordinal.
- **`coverage_rationale`** and each match's **`rationale`** are nullable
  free text. They are internal reviewer-facing commentary only — never
  canonical evidence, never resume wording, never safe to copy verbatim
  into any candidate-facing output. See `docs/domain-model.md` "JobMatch"
  for the full rule.
- **Completeness**: the response must contain exactly one `findings[]`
  entry per `job_analysis_finding_id` supplied in the job input — no
  omissions, no duplicates.
- Nothing in this contract states a computed years-of-experience figure
  anywhere — see `docs/domain-model.md` "JobMatch" for the
  no-years-arithmetic rule.

## Deliberately out of scope here

- The actual system/user prompt text and provider request/response
  envelope — see `docs/job-match-generation.md`.
- A `contradicted` coverage state — considered during design and
  rejected: `CareerFactType` has no "preference/constraint" case, so
  nothing in the schema gives a matcher trustworthy structured grounds to
  assert an actual contradiction rather than an absence.
- Numeric scores, an overall match percentage, or any single fit/ranking
  number — this contract produces per-finding classifications only.
- Retry, validation-failure, or partial-generation handling — a
  `JobMatch` row only ever exists once generation has produced output
  matching this contract; failed/in-progress attempts are not modeled.
