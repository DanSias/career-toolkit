# Canonical Career Data — Proposal (Not Yet Imported)

**Status: PROPOSED.** Nothing described here has been written to the database. This document is a human-readable companion to the structured proposal at `data/canonical-career-data.proposed.json`, which maps explicitly to the models in `docs/domain-model.md`. Review both before any import work begins.

## How to read this

- The JSON file is the deterministic source of truth for the proposal — every record has a stable `key` (or, for Projects/Skills, a `slug` used as `key` here) so it can be cross-referenced and later imported without relying on database auto-increment IDs.
- This document walks through the same proposal in prose, and highlights the user corrections that changed the canonical interpretation from raw resume/portfolio wording.
- **Update:** the two schema gaps this proposal originally surfaced (Role date precision, Metric ranges) are now resolved in the schema itself — see `docs/domain-model.md` ("Role date precision", "Bounded ranges") — and this proposal has been updated to match. A CareerFact quality audit was also completed and its accepted recommendations applied — see "Data-quality cleanup applied" below. **This dataset has since been imported** into the local database via `php artisan career:import` — see `docs/domain-model.md` → "Deterministic import" for the mechanism. This file remains the reviewable source of truth; the database is a deterministic reflection of it, safely re-derivable by re-running the import.

**CareerFact count: 51** (was 53 in the original proposal — 3 removed, 1 split into 2; see changelog below).

## Employers and Roles

| Employer                         | Role                                                  | Dates (real precision) |
| -------------------------------- | ----------------------------------------------------- | ---------------------- |
| RocketGate                       | Developer Support Engineer                            | May 2025 – Current     |
| Pearson Online Learning Services | Data & Analytics Lead Developer / Data Analyst        | Sept 2015 – July 2024  |
| Pearson Online Learning Services | SEO Analyst                                           | June 2013 – Sept 2015  |
| Liquid Gravity Engineering       | Founder & Full Stack Developer / Marketing Consultant | July 2005 – June 2013  |

Total Pearson tenure: June 2013 – July 2024, ~11 years, across two roles. The portfolio's "Nine years" summary sentence is not reproduced as canonical — see "Corrections applied" below.

Every date above is stored as `start_year`/`start_month`/`end_year`/`end_month` integers, matching what's actually evidenced (month/year — no source ever gives a day). No fabricated day-of-month exists anywhere in this proposal or the schema itself.

## Education

Three records, all in `data/canonical-career-data.proposed.json`'s `educations` array, none yet in the resume-level detail beyond degree + field + graduation year (the resume gives no education dates at all):

| Institution                          | Degree              | Field               | End Year |
| ------------------------------------ | ------------------- | ------------------- | -------- |
| University of Central Florida        | Master of Science   | Optics              | 2009     |
| University of Florida                | Master of Science   | Management          | 2005     |
| Embry-Riddle Aeronautical University | Bachelor of Science | Engineering Physics | 2004     |

`start_year` is `null` for all three — no source (resume or portfolio) evidences a start year, only graduation year (sourced from the portfolio's `education.ts`). Education records carry no `verification`/`visibility`/`Evidence` — see `docs/domain-model.md` for why that's by design, not an oversight.

## Projects proposed

| Project                                     | Role                            | Visibility     | Why it's a Project                                                                       |
| ------------------------------------------- | ------------------------------- | -------------- | ---------------------------------------------------------------------------------------- |
| Workflow Intelligence                       | RocketGate                      | public         | Flagship, dedicated case study, rich independent evidence                                |
| Verbatim                                    | RocketGate                      | public         | Dedicated case study                                                                     |
| Transaction Toolkit                         | RocketGate                      | public         | Dedicated case study — distinct from Transaction Remediation Tooling                     |
| Knowledge Exporter                          | RocketGate                      | public         | Dedicated case study                                                                     |
| Transaction Remediation Tooling             | RocketGate                      | **restricted** | Repository + extensive user-confirmed detail; merchant-specific, not publicly documented |
| Nexus: Analytics Command Center             | Pearson (Data & Analytics Lead) | public         | Portfolio "lead" project, dual-sourced with resume                                       |
| Marketing Budget & Forecast Hub             | Pearson (Data & Analytics Lead) | public         | Named portfolio project, dual-sourced with resume                                        |
| Salesforce Migration & Data Warehouse Setup | Pearson (Data & Analytics Lead) | public         | Named portfolio project, dual-sourced with resume                                        |
| Executive Insights Dashboard                | Pearson (Data & Analytics Lead) | public         | Named portfolio project (portfolio-only)                                                 |
| Email Marketing Performance Tracker         | Pearson (Data & Analytics Lead) | public         | Named portfolio project (portfolio-only)                                                 |
| SEO Performance Tracker                     | Pearson (Data & Analytics Lead) | public         | Named portfolio project (portfolio-only)                                                 |
| Recruitment Agent Activity Tracking         | Pearson (Data & Analytics Lead) | public         | Named portfolio project (portfolio-only)                                                 |

**Not created as Projects:** Developer Documentation & Onboarding (RocketGate) and the SEO Analyst role's work stay as Role-level facts — neither has enough distinct, named-entity evidence (architecture, dedicated description) to justify a Project record on its own; a single fact is enough.

## Corrections applied (user confirmations overriding prior wording)

- **Pearson tenure:** canonicalized as June 2013 – July 2024 (~11 years, two roles). "Nine years" is not reproduced as a fact anywhere in the proposal; it's documented in this file and the review report as erroneous portfolio wording, not persisted.
- **Nexus hours saved:** canonicalized as **20+ hours/week**, its own distinct, verified Metric on the Nexus project (`pearson-nexus-hours-saved-per-week`).
- **Marketing Budget & Forecast Hub hours saved:** the old "20+ hours/month" wording is **not** canonical. Canonicalized as an approximate **10–15 hours/month** range with a real bounded-range Metric (`value: 10, value_max: 15, comparator: approximately`), `verification: verified` — the range itself, not a single exact number, is the confirmed fact. The old wording is preserved as superseded Evidence.
- **RocketGate source control:** canonicalized as **GitLab** (`rocketgate-source-control-gitlab`), not GitHub. GitHub is preserved as a separate, career-wide (not RocketGate-specific) fact (`profile-github-personal-projects`), evidenced by the portfolio's own repo/CI.
- **RocketGate ownership:** canonicalized as "independently designed and implemented from team-defined business requirements" — explicitly not "solo." Ownership breadth (ties to Workflow Intelligence's documented Product Design/UX/Architecture/Backend/Frontend/Analytics/AI Integration responsibilities) and the independently-implemented characterization are kept as two separate facts, per your explicit guidance.
- **AI-assisted knowledge management:** canonicalized as a Role-level fact citing Knowledge Exporter, Verbatim, and NotebookLM together, without rewriting Knowledge Exporter itself as an AI product (it stays deterministic/non-AI in its own fact).
- **Merchant integration:** canonicalized as integration _enablement/support_ (meeting merchants, directing them to existing libraries, testing flows, troubleshooting) — not "wrote every merchant's integration." No merchant counts or outcome metrics invented.
- **Transaction Reconstruction vs. Transaction Remediation vs. Transaction Toolkit:** three distinct things now. Transaction Toolkit (public, portfolio-documented investigation/analytics app) is unrelated to Transaction Remediation Tooling (restricted, repository + user-confirmed, the merchant data-correction engagement). "Transaction Reconstruction" is preserved only as the source repository's own name for the original, narrower scope of that second project.
- **Transaction Remediation metrics:** the 43,348-transaction final audit is represented as two separate facts/metrics — population analyzed, and population found in the desired state — deliberately never phrased as "100% accuracy" or a success rate. The total corrected-records figure is canonicalized conservatively as **32,000+** (with the precise 24,589 + 7,457 = 32,046 derivation preserved in the metric's `scope_note`), kept structurally distinct from the 43,348 audit figure.
- **$25M+ / $1.3M / 85%:** all three guardrails from the prior reconciliation are preserved unchanged in this proposal (see the Metrics section of the review report).

## Data-quality cleanup applied

From the CareerFact quality audit in the prior pass, five changes were accepted and applied:

1. **Workflow Intelligence ownership duplication.** `rocketgate-workflow-intelligence-independently-implemented` no longer restates the general "independently implemented" conclusion — that stays once, at Role level, in `rocketgate-independently-implemented-from-team-requirements`. The project-level fact now covers only what's specific to it: the Flow-tool origin story, internal documentation, Jira tickets, and credentials/API access the team supplied.
2. **Merchant integration split.** `rocketgate-merchant-integration-enablement` is now two atomic facts: `rocketgate-merchant-integration-technical-contact` (meeting merchants, assessing their stack, directing them to the right integration library/approach) and `rocketgate-merchant-integration-testing-troubleshooting` (testing payment flows, troubleshooting, answering technical questions).
3. **Approved-user counts.** `rocketgate-transaction-remediation-phase1-approved-users` and `-phase2-approved-users` are removed as standalone CareerFacts. The numbers (1,780 / 44) are preserved as `scope_note` context on the corresponding `-transactions-corrected` metrics — not lost, just no longer treated as independently reusable accomplishments on their own.
4. **Scope-expansion fact kept as-is.** `rocketgate-transaction-remediation-phase2-initial-scope-expansion` (390 → investigation → 7,457) is unchanged — it narrates a real investigation/scope-discovery story, distinct from a bare count.
5. **Career-profile summary removed.** `profile-full-stack-engineer-summary` restated the resume's marketing "Professional Summary" paragraph with no information beyond what the Laravel/React/TypeScript Skills and their own project-level facts already establish with stronger, more specific evidence. Removed rather than reworded.

`rocketgate-workflow-intelligence-what-it-is` ("single evidence-linked source of truth") was reviewed and left unchanged — accurate architectural wording, not marketing fluff, in context.

## Schema gaps resolved since the last review

Two issues the prior review flagged as blocking were resolved this pass — both in the schema itself, not worked around in the data:

1. **Role date precision.** `roles.start_date`/`end_date` (SQL `DATE`) were replaced in place with `start_year`/`start_month`/`end_year`/`end_month` integer columns, since no real data had been imported yet under the old shape. Application-level validation (a `saving` listener, same pattern as CareerFact's attribution check) rejects out-of-range months, an end month with no end year, and an end before the start. See `docs/domain-model.md` → "Role date precision".
2. **Metric ranges.** `career_fact_metrics` gained a nullable `value_max` column. `value` stays the primary/lower figure; `value_max` is set only for a genuine two-sided range and is never a fabricated midpoint. Application-level validation rejects `value_max < value`. See `docs/domain-model.md` → "Bounded ranges". The Marketing Budget & Forecast Hub fact now uses this directly (`value: 10, value_max: 15`).

## What's deliberately not in this proposal

- **JobApplication / ResumeVariant / ResumeFactSelection:** out of scope per `docs/domain-model.md`; not touched.
- Client names, industries, or project names for Liquid Gravity Engineering's metrics — genuinely not established by either source, and none are invented.
- Any merchant name, credential, API key, or customer identifier — the Transaction Remediation facts describe process and aggregate counts only.
