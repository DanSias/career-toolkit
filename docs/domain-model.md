# Career Toolkit — Core Domain Model

This is the first version of the domain model behind Career Toolkit's
canonical career data. It intentionally implements only the data layer:
models, migrations, relationships, and integrity rules. No UI, importers,
AI functionality, resume generation, or authentication behavior exists yet.

## Entity overview

| Entity          | Belongs to                                                     | Holds                                                             |
| --------------- | -------------------------------------------------------------- | ----------------------------------------------------------------- |
| `User`          | —                                                              | Laravel's standard account/ownership root                         |
| `CareerProfile` | `User`                                                         | A person's canonical career dataset                               |
| `Employer`      | `CareerProfile`                                                | An organization                                                   |
| `Role`          | `Employer`                                                     | One title/period of employment                                    |
| `Project`       | `Role`                                                         | A named, evidenced body of work (zero or many per Role)           |
| `CareerFact`    | `CareerProfile` (+ attributed to one of the four models above) | One atomic, selectable claim                                      |
| `Evidence`      | `CareerFact`                                                   | One piece of provenance for a fact                                |
| `Metric`        | `CareerFact` (optional, 1:1)                                   | Structured quantitative data for a fact                           |
| `Skill`         | `CareerProfile`                                                | A build technology, platform integration, capability, or practice |
| `Education`     | `CareerProfile`                                                | One academic credential — plain structured data, not a CareerFact |
| `JobPosting`    | `CareerProfile`                                                | The verbatim source material for a target job                     |
| `JobAnalysis`   | `JobPosting`                                                   | One versioned, immutable structured-analysis snapshot of a posting |
| `JobAnalysisFinding` | `JobAnalysis`                                             | One discrete observation extracted from a posting                 |
| `JobAnalysisFindingEvidence` | `JobAnalysisFinding`                              | One verbatim excerpt substantiating a finding                     |

`CareerFact` ↔ `Skill` is many-to-many via a plain pivot table
(`career_fact_skill`). `project_skill` also exists but is currently
unused — see "Project skills are derived, not stored" below.

## Relationships

```text
User
 └── CareerProfile (hasMany)
      ├── Employer (hasMany)
      │    └── Role (hasMany)
      │         └── Project (hasMany)
      │              ↔ Skill (belongsToMany — unused; see "Project skills are derived, not stored")
      │
      ├── CareerFact (hasMany, direct ownership — always set)
      │    ├── attributable → CareerProfile | Employer | Role | Project (morphTo)
      │    ├── Evidence (hasMany)
      │    ├── Metric (hasOne, optional)
      │    └── Skill (belongsToMany)
      │
      ├── Skill (hasMany)
      │
      ├── Education (hasMany)
      │
      └── JobPosting (hasMany)
           └── JobAnalysis (hasMany — zero or more snapshots, no "current" pointer)
                └── JobAnalysisFinding (hasMany)
                     └── JobAnalysisFindingEvidence (hasMany)
```

A `CareerFact` has **two relationships** to the rest of the graph: it
always `belongsTo` a `CareerProfile` directly (so "all facts for this
profile" is a plain indexed lookup, never a polymorphic join), and it is
separately `attributable` to whichever entity the claim is actually about.
Nothing at the database level ties these two together (a polymorphic
column can't carry a real foreign key) — so this is enforced at the
application layer instead. See "Attribution integrity" below.

## Attribution integrity

**Invariant:** a `CareerFact`'s `attributable` target must belong to the
_same_ `CareerProfile` as the fact itself. Ownership of an attribution
target is resolved per-model: `Employer`/`Role` walk existing
relationships (never a stored `career_profile_id` of their own);
`Project` stores `career_profile_id` directly (required on every
Project, professional or independent — see "Project ownership"
below) since an independent Project has no `Role` to walk through at
all:

```text
CareerProfile → itself
Employer      → employer.career_profile_id
Role          → role.employer.career_profile_id
Project       → project.career_profile_id (stored directly — see "Project ownership")
```

Each of the four attributable models implements
`App\Contracts\HasCareerProfileOwnership::ownerCareerProfileId()`, doing
exactly that traversal for itself. `CareerFact` doesn't need to know how
any particular model resolves its owning profile — it just calls
`ownerCareerProfileId()` polymorphically and compares the result to its
own `career_profile_id`.

**Enforcement is centralized in one place:** a `#[Boot]`-attributed method
on `CareerFact` (`enforceAttributionIntegrity()`) registers a `saving`
listener — the same convention already used by `Employer`/`Role`/`Project`
for their deletion-safety hooks. `saving` fires on every create _and_
every update, so the same check protects all of:

- an unsupported `attributable_type` (not in the enforced morph map, or
  mapped to a model that doesn't implement `HasCareerProfileOwnership`)
- a nonexistent `attributable_id`
- a same-profile violation on create
- changing an existing fact's `attributable_*` to a cross-profile target
- changing an existing fact's `career_profile_id` such that its
  _unchanged_ attribution becomes cross-profile

A violation throws `App\Exceptions\InvalidCareerFactAttributionException`
and the save does not happen. See
`tests/Feature/Domain/AttributionIntegrityTest.php` for all of the above,
each exercised through `CareerFact::factory()->create()` / `->update()` —
the real write path, not a directly-called helper.

**Why a model event, not a form request or importer-side check:** the
requirement is that every writer — an eventual importer, a controller
action, a factory, `php artisan tinker` — goes through the same validated
path. A `saving` listener on the model itself is reachable from all of
them without any of them having to remember to call something; a
FormRequest or service-layer check would only protect whichever caller
remembered to invoke it.

**Accepted limitation:** this is application-layer enforcement, not a
database constraint — a raw `DB::table('career_facts')->insert(...)` or
`->update(...)` bypasses Eloquent entirely and is not caught. No database
trigger was added to close this gap, by design (see the task that produced
this pass); every intended writer of this application goes through
Eloquent.

## Why there is no separate `Experience` entity

The initial product brief was unsure whether "Experience" and "Role" were
one concept or two. The reconciliation work that preceded this task
answered that with a concrete case: Daniel's Pearson tenure is one
`Employer` with two successive `Role`s (SEO Analyst, then Data & Analytics
Lead Developer) that have different titles, different date ranges, and
different metrics attached to each of them specifically (the $1.3M SEO
budget belongs to the first role; the 85%/$25M+/hours-saved figures belong
to the second). A single `Employer` + ordered `Role`s already represents
this correctly. An employer-level narrative (e.g. "grew from SEO analyst
into the analytics platform lead") is just a `CareerFact` attributed to the
`Employer` — it doesn't need its own table. Introducing a separate
`Experience` entity between `Employer` and `Role` would be redundant: it
would either duplicate what `Employer` already provides, or duplicate what
an `Employer`-attributed `CareerFact` already provides.

## Role date precision

`Role` dates are `start_year`/`start_month`/`end_year`/`end_month` — four
nullable-where-appropriate integer columns — not a SQL `DATE` pair. This
was discovered while mapping real evidence for the first canonical
dataset: every source (resume, portfolio, direct confirmation) only ever
gives month/year for employment dates, never a day. An earlier version of
this schema used `DATE` columns, which would have forced persisting a
fabricated day (e.g. `2013-06-01`) that no source actually supports —
silently asserting precision that doesn't exist. That version was replaced
in place (not layered under a new migration) since no real data had been
imported yet.

Columns: `start_year` (`unsignedSmallInteger`, required), `start_month`
(`unsignedTinyInteger`, nullable — a role can be evidenced to year-only
precision), `end_year` (nullable, for a current role), `end_month`
(nullable). No day-level field exists anywhere in this table, by design.

**Invariants**, enforced the same way as CareerFact's attribution check —
a `#[Boot]`-attributed `saving` listener on `Role`
(`enforceValidDateRange()`), so every writer that goes through Eloquent is
covered automatically:

- `start_month`/`end_month`, when present, must be 1–12
- `end_month` cannot be set without `end_year` (an end date needs at least
  a year)
- `end_year` cannot be before `start_year`
- within the same year, `end_month` cannot be before `start_month`

A violation throws `App\Exceptions\InvalidRoleDateRangeException`. See
`tests/Feature/Domain/RoleDateRangeTest.php`.

Education dates (see "Education" below) are year-only by the same
reasoning — no source ever evidences even a month for a degree, only a
graduation year.

## Why `Metric` is subordinate to `CareerFact`

Every quantitative claim encountered during reconciliation (85% reporting
reduction, $25M+ spend visibility, $1.3M budget managed, 37%/201.2%
Liquid Gravity figures) was a property of exactly one claim — never shared
or referenced independently across multiple facts. There was no case
where a `Metric` needed to be queried, linked to, or exist on its own.
`career_fact_metrics` is a one-to-one table (`career_fact_id` is unique)
storing `value`, `value_max`, `unit`, an optional `comparator`, a
`scope_note`, and a `guardrail` — the last field exists specifically to
prevent a future resume generator from restating "gave visibility into
$25M+ in tracked spend" as "managed a $25M budget" or "generated $25M in
revenue."

If a real need for independently-addressable metrics ever appears (e.g.
the same external number needs to be referenced by two different facts
without duplication), it can be extracted into its own entity then —
nothing about this shape forecloses that.

### Bounded ranges

`value` is always the primary/lower figure; `value_max` is `null` for a
single-ended value (`85`, `25000000` with `comparator: at_least`, `20`
with `comparator: at_least`) and set only when a claim is a genuine
two-sided range with no single confirmed number — e.g. "approximately
10–15 hours/month" is `value: 10, value_max: 15, comparator:
approximately`. This was added after the first canonical-dataset mapping
produced exactly that case and the prior single-`value` shape had no way
to represent it without inventing a midpoint (`12.5`) nobody confirmed.

`comparator` continues to describe the whole figure, range or not —
`approximately` on a range means "approximately this range," not fuzziness
around a single point.

**Invariant:** `value_max`, when present, cannot be less than `value`.
Enforced the same way as the other model-level invariants in this
document — a `#[Boot]`-attributed `saving` listener on `Metric`
(`enforceValidRange()`) throws `App\Exceptions\InvalidMetricRangeException`
on violation, covering every Eloquent write path. See
`tests/Feature/Domain/MetricRangeTest.php`. No general-purpose measurement
system (units of range, comparator combinations beyond this, etc.) was
built beyond this one nullable column — it solves the one real case found
so far.

## Evidence and provenance

`Evidence` is a first-class model (table `career_fact_evidence`) with a
plain `has-many` relationship to `CareerFact` — one fact commonly has
several. It uses ordinary nullable columns rather than an opaque JSON blob
for the fields common across every source type discovered so far:

| Column                        | Used by                                           |
| ----------------------------- | ------------------------------------------------- |
| `source`                      | all — which kind of source (see below)            |
| `document`                    | resume                                            |
| `path`                        | portfolio, repository                             |
| `section`                     | resume                                            |
| `locator`                     | resume, portfolio, repository                     |
| `quoted_text`                 | any source, when the literal wording was captured |
| `confirmed_at`                | user_confirmed                                    |
| `note`                        | user_confirmed, or a free note on any source      |
| `metadata` (`json`, nullable) | escape hatch — see below                          |

This covers every shape from the reconciliation examples (resume
document/section/locator, portfolio path/locator, repository path/locator,
user_confirmed confirmed_at/note) as normal, queryable, indexable columns.
A `metadata` JSON column is included as a genuine extensibility escape
hatch for source-specific detail that doesn't warrant its own column (e.g.
a future source type needing a commit SHA or a line range) — but it is not
where the common provenance fields live, since those are known, stable,
and worth being able to query directly.

`source` is a plain `string` column, cast through the `App\Enums\EvidenceSource`
backed enum (`resume`, `portfolio`, `repository`, `user_confirmed`). This
list has already grown once during reconciliation (portfolio → +repository)
and should be expected to grow again — adding a case is a one-line code
change, never a migration.

### Source wording

Differing literal wording between sources (e.g. a resume bullet says "20+
hours per month" while a portfolio phrase says "20+ hours per week") needs
to be preserved rather than silently collapsed into one canonical
statement. This lives as a `quoted_text` column directly on `Evidence`,
not as a separate `CareerFactSourceWording` entity: a given evidence row
already represents "this source, at this locator," and the literal wording
found there is naturally 1:1 with that row — it doesn't need independent
querying or its own lifecycle (it's written once, alongside the evidence
row, and never referenced except through its fact). A separate entity
would only re-implement what `Evidence` already provides, at the cost of
an extra join. If a future need arises for multiple distinct wordings
_within_ a single evidence citation over time, this can be revisited then.

## Verification vs. visibility

These are deliberately independent columns on `CareerFact`, both stored as
plain strings and cast through PHP backed enums (`App\Enums\Verification`,
`App\Enums\Visibility`) rather than database enums:

- **Verification** (`verified` / `strongly_supported` / `needs_confirmation`)
  answers _"do we trust this claim?"_ — independent of where it came from.
  A single-source, user-confirmed fact can be `verified`; a
  well-documented, multi-source portfolio claim can still be
  `needs_confirmation` if its exact framing is unresolved.
- **Visibility** (`public` / `restricted` / `private`) answers _"may an
  automated resume/application generator expose this?"_ — a
  confidentiality question, unrelated to trust.

Neither is derived from the other, and neither is derived from a fact's
evidence coverage (a skill or fact referenced by three sources isn't
automatically more "verified" than one referenced by one — see
`tests/Feature/Domain/VerificationIndependenceTest.php`).

`Project` also carries an optional `default_visibility` column, independent
of any individual `CareerFact`'s visibility — because an entire project's
_existence_ may need to stay non-public even before any fact about it is
reviewed (the reconciliation's Transaction Reconstruction tooling is a
concrete example: no public case study, real API hostnames referenced).
Nothing currently derives a fact's visibility from its project's default;
that would need to be an explicit, deliberate application-level decision.

## Skills

`Skill` holds no "evidence strength" column. Evidence strength
(`project_evidenced` / `experience_evidenced` / `resume_asserted` / etc.,
per the reconciliation report) is a property of _which facts and projects
reference a skill and how well those are verified_ — not a fixed property
of the skill itself. Storing it directly on `Skill` would let it drift out
of sync with the facts that actually justify it. It's derivable via a
query (e.g. the strongest verification level among `CareerFact`s and
`Project`s referencing a given skill) whenever it's actually needed; no
cached/summary column is implemented here because nothing yet consumes it.

`category` (`build_technology` / `platform_integration` / `capability` /
`practice`) is a plain string cast through `App\Enums\SkillCategory`, kept
distinct per category rather than flattened into one undifferentiated tag
list — this is what let the reconciliation report distinguish "GitLab"
(platform integration, project-evidenced) from "Docker" (build technology,
resume-asserted only) instead of treating both as interchangeable "skills."

## Education

`Education` belongs to `CareerProfile` directly — a flat list, not nested
under any Employer/Role/Project. It's deliberately plain structured
profile data, the same tier as `Employer`: `institution`, `degree`,
`field_of_study` (nullable), `start_year` (nullable), `end_year`
(nullable), `sort_order`. It carries **no** `verification`, `visibility`,
or `Evidence` relationship — those are CareerFact concepts, and a degree
isn't a selectable, sourced claim the way a resume bullet is; it's a fact
about the person, recorded once. Year-only precision, for the same reason
`Role` is month/year-only: no source found during the first
canonical-dataset mapping evidenced anything more precise than a
graduation year for any degree (the resume gives no education dates at
all; the portfolio gives only a `graduated` year per degree). Deliberately
excludes GPA, honors, activities, coursework, thesis, and location — none
of that is evidenced by any current source, and none was added
speculatively.

## Stable identifiers

Auto-increment `bigint` primary keys are used throughout — simple, fast,
and standard for a local-first, single-user SQLite application with no
sharding or public-facing enumeration concerns. UUID/ULID primary keys
were considered and rejected: nothing here needs distributed ID
generation, and switching every PK/FK would add real complexity for no
concrete benefit yet.

Auto-increment IDs are _not_ stable across a database rebuild, re-seed, or
future import/export round-trip, though — and `CareerFact` and `Project`
are specifically expected to be referenced externally (evidence citations,
resume-generation history, human-readable YAML/JSON snapshots). Those two
models each carry a separate, human-readable, unique string:

- `career_facts.key` — a stable slug, globally unique
- `projects.slug` — a stable slug, globally unique

`Employer` and `Role` do not get a separate stable identifier: nothing in
the reconciliation evidence showed them being cited externally the way a
fact or a project is (evidence citations point at documents/paths, not at
internal entities), and `Employer.name` already serves as a natural lookup
key for a future importer. Global (not per-profile) uniqueness was chosen
for both `key` and `slug` for simplicity, appropriate to a single local
user's dataset — this can become a composite `(career_profile_id, slug)`
unique constraint later if the application ever needs to support unrelated
multi-tenant profiles.

**This was revisited and confirmed, not just assumed** — the proposed
canonical dataset (`data/canonical-career-data.proposed.json`) uses
JSON-only `employer_key`/`role_key` values (e.g. `"rocketgate"`,
`"pearson-seo-analyst"`) purely so its own records can cross-reference
each other within that one file. No schema column was added to
accommodate them, on purpose. A future deterministic importer resolves
these the same way any of this proposal's structural references resolve,
without ever treating a proposal key as a persisted field:

1. **Employer** — look up by `(career_profile_id, name)`; create if
   absent.
2. **Role** — look up by `(employer_id, title, start_year, start_month)`;
   create if absent. This tuple is already unique in practice for one
   person's real career history (no two roles at the same employer share
   both a title and an exact start), and doesn't require a new column to
   get there.
3. **Project** — resolves directly via the real `slug` column; no lookup
   tuple needed.
4. **CareerFact** — resolves directly via the real `key` column.

The proposal's `employer_key`/`role_key` values exist only to make _this
one file_ self-consistent and reviewable; an importer is expected to
re-derive Employer/Role identity from their real columns (name, title,
dates) at import time, the same way it would for any dataset it had never
seen before.

## Deletion behavior

| Deleting...         | ...cascades (DB-level `onDelete('cascade')`)                                             |
| ------------------- | ---------------------------------------------------------------------------------------- |
| `CareerProfile`     | `Employer`, `CareerFact`, `Skill`, `Education`, `JobPosting` (and everything under them) |
| `Employer`          | `Role` → `Project`                                                                       |
| `Role`              | `Project`                                                                                |
| `CareerFact`        | `Evidence`, `Metric`, `career_fact_skill` pivot rows                                     |
| `Skill` / `Project` | their pivot rows only, never the other side                                              |
| `JobAnalysis`       | `JobMatch` → `JobMatchFinding` → `CareerFactMatch`/`EducationMatch`                       |
| `JobMatch`          | `JobMatchFinding` → `CareerFactMatch`/`EducationMatch`                                   |

`CareerProfile` is treated as the true aggregate root: deleting it deletes
everything it owns, with no special handling needed — **except** that a
`CareerProfile` owning a `CareerFact`/`Education` already cited by a
`JobMatch` cannot be deleted at all until that history is dealt with, the
same way any other RESTRICT-protected dependent blocks deletion; a
`CareerProfile` with no `JobMatch` history still deletes freely. See
"JobMatch" below for why `career_fact_matches.career_fact_id` and
`education_matches.education_id` RESTRICT rather than cascade — the one
deliberately non-cascading FK direction in this table, alongside the
`Employer`/`Role`/`Project` reassignment case just below.

**The one deliberately non-cascading case:** deleting an `Employer`,
`Role`, or `Project` must never destroy a `CareerFact` merely because that
fact happens to be attributed to it. A `CareerFact`'s polymorphic
`attributable_type`/`attributable_id` columns have no database-level
foreign key (a single FK can't reference more than one table), so nothing
would delete a fact automatically — but leaving it pointing at a row that
no longer exists would be a silent dangling reference, which is just as
unsafe. Instead, `Employer`, `Role`, and `Project` each register a
`#[Boot]`-attributed static method that hooks their `deleting` event and
re-points any `CareerFact`s attributed to them (and, for `Employer`/`Role`,
to any `Role`/`Project` underneath them that the DB cascade is about to
silently remove) back to the fact's own `CareerProfile`, via
`CareerFact::reassignAttributionToProfile()`. This has to walk the
about-to-be-cascaded descendants explicitly, because Eloquent's
`deleting` event does **not** fire for rows removed by a database-level
`onDelete('cascade')` — only for the row `->delete()` was actually called
on. See `app/Models/Employer.php`, `Role.php`, `Project.php`, and
`tests/Feature/Domain/DeletionBehaviorTest.php` for the exact behavior and
its test coverage.

This reassignment always re-points a fact at its own `CareerProfile` (same
profile it already belongs to via `career_profile_id`), so it always
passes the attribution-integrity check above by construction — the two
mechanisms don't conflict.

## Enum/value strategy

Every "list of values expected to evolve" column (`fact_type`,
`verification`, `visibility`, `evidence.source`, `skill.category`,
`metric.comparator`, `project.default_visibility`) is a plain `string`
database column — never a database-level `ENUM` — paired with a PHP
backed enum (`app/Enums/`) used via Eloquent's `casts()`. Extending any of
these lists is a one-line addition to the enum class; it never requires a
migration. `metric.unit` is deliberately left as a plain string with _no_
backing enum — the space of possible units (percent, USD, hours/month,
counts, ...) is open-ended by nature, not a closed list of categories.

## Unresolved competing claims stay as separate CareerFacts

No schema exists (or is needed) for "conflicting facts." When two sources
disagree and it isn't yet known which is right — for example, the resume
attributes "20+ hours/month" to the Marketing Forecasting Platform while a
separate resume bullet attributes "20+ hours/week" to Nexus — each claim
is simply its own `CareerFact`, each with `verification: needs_confirmation`,
its own `Evidence`, and (where quantified) its own `Metric`. Nothing
merges them, silently picks one, or requires them to agree. Resolving
which is correct — both valid, one wrong, or one mis-attributed — is a
data decision made later (by the user, during import review), not a
schema concern.

**Future rule, not implemented yet:** once resume-generation exists, a
`CareerFact` with `verification: needs_confirmation` should be excluded
from automatic selection unless a reviewer has explicitly approved it for
that use. No selection/generation logic exists yet to enforce this — it's
recorded here so the constraint isn't lost before that logic is built.

## Deterministic import

`php artisan career:import [path] [--user-email=]` (`App\Console\Commands\ImportCanonicalCareerData`)
loads the reviewed canonical dataset — a JSON file, never an LLM at
import time — and persists it. It's the one sanctioned way real career
data enters the database; the data itself is never added to a generic
model factory (factories exist only to generate synthetic data for
tests).

**Resolution, not raw insertion.** Every entity is matched by a stable
natural key before being written, using exactly the rules in "Stable
identifiers" above — Employer by `(career_profile_id, name)`, Role by
`(employer_id, title, start_year, start_month)`, Project by `slug`, Skill
by `(career_profile_id, slug)`, CareerFact by `key`, Education by
`(career_profile_id, institution, degree, field_of_study)`. Everything
goes through `updateOrCreate`, so the command is idempotent by
construction: running it twice against an unchanged file produces the
same database state as running it once, and running it again after an
edited file deterministically applies that edit — it doesn't just skip
because a row already exists.

**Evidence and pivots are fully replaced per parent, not diffed.** A
CareerFact's `Evidence` rows are deleted and recreated from the file on
every import, and its `Skill` associations are `sync()`'d — both are the
simplest correct way to guarantee the database matches the file exactly
after each run, without building a change-diffing mechanism this
one-file, human-reviewed dataset doesn't need. A `Metric` is
upserted when the file has one and deleted when it doesn't, for the same
reason.

**Fails loudly, not silently.** Before writing anything, the command
checks the dataset itself for natural-key collisions (e.g. two roles that
would resolve to the same Employer/title/start) and refuses to import if
it finds one — `updateOrCreate` alone would otherwise silently merge them.
During import, any reference that doesn't resolve (an `employer_key` a
Role points at, a `role_key` a Project points at, an `attributable`
target a CareerFact points at, a skill key) throws
`App\Exceptions\CanonicalDataImportException` immediately. The whole run
is wrapped in a single `DB::transaction()`, so any failure — including a
domain-integrity rejection from CareerFact's attribution check, Role's
date-range check, or Metric's range check, all of which still fire
normally since this command writes through Eloquent like everything
else — rolls back everything from that run, never leaving a partial
import in place.

**The local User.** `CareerProfile.user_id` is required, and the dataset
doesn't carry account data (rightly — it's career data, not credentials).
The command resolves the local owner via `--user-email` (defaulting to
the project owner's own address) with `firstOrCreate`, matching the
"eventually one local user" expectation from the initial application
setup — it never fabricates a second identity on repeat runs.

See `tests/Feature/Domain/ImportCanonicalCareerDataTest.php` for first-run,
idempotent-second-run, deterministic-update, and every failure case above,
each exercised against a small fixture dataset built in the test itself —
never against the real canonical data.

## Current CareerProfile resolution

The application is local-first, single-user, with no login and no
profile-switching UI — but "which `CareerProfile` is the active one" was
being decided ad hoc (`CareerProfile::query()->first()`) directly inside
`CareerDataController`. That's centralized now in
`App\Support\CurrentCareerProfile`, a small stateless resolver — not a
tenancy/context framework, just one place that owns this one rule:

```php
CurrentCareerProfile::resolve(): CareerProfile      // throws NoCareerProfileException if none exists
CurrentCareerProfile::tryResolve(): ?CareerProfile  // returns null instead
```

**Deterministic rule when more than one `CareerProfile` row exists**
(nothing prevents this at the database level, even though the product
only ever creates one): the oldest — lowest `id`, i.e. the first one this
application ever owned (`CareerProfile::query()->oldest('id')->first()`).
Not documented behavior that happened to fall out of insertion order —
`tryResolve()` orders explicitly, so it's true regardless of how rows were
created.

**Two methods, not one, because two genuinely different needs exist:**
`resolve()` throws `App\Exceptions\NoCareerProfileException` when nothing
exists — used by `JobPostingController`, where a missing profile is a
real failure (a `JobPosting` has nowhere to attach). `tryResolve()`
returns `null` — used by `CareerDataController::index()`, which has a
deliberate, already-tested empty state for "no profile yet" (a freshly
migrated, not-yet-imported database). Silently returning `null` from
`resolve()` and making every caller re-check would be exactly the
"downstream code assumes a profile" failure mode this exists to prevent;
splitting into two named methods makes each call site's assumption
explicit instead.

## Project skills are derived, not stored

`project_skill` exists as a table but holds zero rows across the entire
canonical dataset, and nothing populates it. Rather than force-populating
it (which would mean asserting facts about a Project that no CareerFact
actually backs) or leaving Project skill display simply blank, the
decision for now is:

> A Project's skills are the distinct Skills attached to CareerFacts
> attributed **directly** to that Project — never inherited from its
> Role, and never from `project_skill`.

`Project::derivedSkills(): Illuminate\Support\Collection<int, Skill>`
implements exactly this: `$this->careerFacts->flatMap(fn ($fact) =>
$fact->skills)->unique('id')->values()`. It reads whatever's already
eager-loaded (`careerFacts.skills`) rather than issuing its own query, so
a page listing many Projects (the Career Data index) stays at a fixed,
small query count instead of one extra query per project. `Project::skills()`
— the actual `project_skill` relation — is unchanged and still present:
kept, not removed, for the case a real need for **independent**,
fact-unbacked Project-level skill assertions shows up later. Until then,
maintaining two sources of truth for the same information would only let
them drift apart, so the UI reads `derivedSkills()` exclusively.

No caching or duplication was added: `derivedSkills()` is computed at
read time from data that already exists, not persisted anywhere new.

## Project ownership

A `Project` is either **professional** (owned by a `Role`) or
**independent/personal** (owned directly by a `CareerProfile`, with no
`Role` at all — e.g. a self-directed side project never done through an
employer). Both kinds are the same `Project` model; there is no separate
`IndependentProject` class and no XOR pair of ownership columns.

`projects.career_profile_id` is **required on every Project**;
`projects.role_id` is **nullable**:

- `role_id !== null` — a professional Project owned by that Role. Its
  `career_profile_id` must equal that Role's own owning CareerProfile
  (`role.employer.career_profile_id`) — checked deterministically on
  every save, never merely assumed consistent.
- `role_id === null` — an independent Project, owned directly by
  `career_profile_id`.

This design was chosen over two alternatives considered and rejected:
an explicit project "context/type" enum (redundant — `role_id === null`
already unambiguously means "independent" for this two-kind model, so a
separate type column would just duplicate that signal) and a wholly
separate `IndependentProject` model (would duplicate CareerFact's morph
map, `ResumeEligibility`'s Project-level visibility backstop, and the
resume-payload builders' attribution-resolution logic across two
parallel models for one relatively small ownership difference).

**Enforcement** mirrors `CareerFact::enforceAttributionIntegrity()`
exactly: a `#[Boot]`-attributed `saving` listener on `Project`
(`enforceOwnershipIntegrity()`) runs on every create and update. When
`role_id` is set, it resolves that Role's real owning CareerProfile and
throws `App\Exceptions\InvalidProjectOwnershipException` on any
mismatch or a nonexistent Role. When `role_id` is set but
`career_profile_id` is omitted, it is auto-filled from the Role's
owning CareerProfile — a convenience that keeps every existing
`Project::factory()->for($role)`-style call site and the canonical
importer's role-attached Projects working unchanged, without weakening
the check itself (an explicitly-supplied, contradicting
`career_profile_id` is still rejected). An independent Project
(`role_id` null) is never auto-filled — the caller must supply
`career_profile_id` explicitly, since there is no Role to derive it
from.

`Project::ownerCareerProfileId()` (the `HasCareerProfileOwnership`
implementation `CareerFact`'s own attribution check compares against)
simply returns `$this->career_profile_id` directly now, rather than
traversing `role.employer` — the same column this section's own
invariant already keeps correct, so a CareerFact attributed to either
kind of Project resolves its owning profile identically.

**Migration safety:** every pre-existing Project was created before
`career_profile_id` existed and has only `role_id`. The
2026_09_09_000006 migration backfills it via the same
nullable-column-first, backfill, verify, then-finalize pattern already
established for the ResumeVariant snapshot migrations (see "Safe
populated-table migration pattern" precedent in
`App\Support\ResumeVariant\ExperienceRoleSnapshotBackfiller`): add
`career_profile_id` nullable, backfill every row from
`role_id -> roles.employer_id -> employers.career_profile_id`
(`App\Support\CareerData\ProjectCareerProfileBackfiller`), verify zero
unresolved rows, abort without finalizing on any failure, then make
`career_profile_id` required and `role_id` nullable. See
`tests/Feature/Domain/ProjectOwnershipMigrationTest.php`, which runs
the real migration file against seeded old-schema data on an isolated
scratch connection — never a simulation of it.

## JobPosting

`JobPosting` belongs to `CareerProfile` (`career_profile_id`, cascade on
delete — the same ownership pattern as `Employer`/`Skill`/`Education`; no
separate `User`-level ownership was introduced since `CareerProfile`
ownership is already sufficient). It holds only the verbatim source
material for a target job: `company`, `title`, `source_url` (nullable),
`location` (nullable), `description` (required, captured as-is). No
`source_name`, analysis JSON, keywords, requirements, match score,
selected-facts, or application-status field exists — none was justified
by the current intake workflow, and adding one speculatively would be
exactly the kind of premature design this document keeps warning against
elsewhere.

**`description` is deliberately never rewritten.** Laravel's default
global `TrimStrings` middleware would otherwise trim its leading/trailing
whitespace like any other input; `bootstrap/app.php` explicitly excludes
it (`$middleware->trimStrings(except: ['description'])`) while leaving
ordinary single-line fields trimmed normally. Nothing else in the intake
path (validation, the controller, the model) touches its content. This
matters because the eventual questions this field needs to answer —
"what did we tailor this resume against," after the live posting has
changed or vanished — depend on it being the actual submitted text, not
a normalized approximation of it.

`JobPosting` intentionally has no relationship to `CareerFact`,
`Evidence`, or any other canonical-data entity. It does have one
relationship layered on top of it now — `jobAnalyses` (hasMany), see
below — but that relationship stays entirely on the "what does this job
want" side; `JobPosting` itself still holds no analysis, keyword,
requirement, match-score, or selected-fact data as columns. CareerFact
matching is a later milestone.

## JobAnalysis

`JobAnalysis`, `JobAnalysisFinding`, and `JobAnalysisFindingEvidence`
together are a **derived, AI-generated interpretation of one
`JobPosting`** — not source data. `JobPosting.description` is the
source; a `JobAnalysis` is one structured reading of it, produced by
extracting findings from that text. Nothing in this layer is
canonical-career-data — it describes the job, never the candidate. See
`docs/job-analysis-contract.md` for the exact structured-output shape
this maps onto, and `docs/job-analysis-generation.md` for how a
snapshot is actually produced and persisted — the generation pipeline,
provider boundary, and prompt/schema version conventions live there
rather than in this document.

**Each `JobAnalysis` row is a complete, versioned, immutable snapshot.**
A `JobPosting` can accumulate zero or more `JobAnalysis` rows over time
(e.g. re-running analysis after a prompt or model change), and there is
deliberately no `current_job_analysis_id` pointer on `JobPosting` —
"which snapshot is current" is a UI/query concern for whenever an actual
consumer of this data is built, not a schema-level fact today. Once a
`JobAnalysis` (or any of its findings/evidence) is created, none of its
fields are ever updated in place; a correction is a new `JobAnalysis`
row, exactly as a `CareerFact` correction would be a new fact rather
than a mutated one. This is enforced at the model layer (a `saving`
guard on `JobAnalysisFinding` validates on every save, while an
`updating` guard on all three models blocks any change to an
already-persisted row) rather than left as a convention, and the guard
is deliberately on `updating`, not `saving`, so that constructing a
snapshot — creating its `JobAnalysisFinding` and
`JobAnalysisFindingEvidence` children — is never blocked; only editing
an existing row is.

**`JobAnalysisFinding` is the unit of interpretation.** Each row is one
discrete observation pulled from the posting — a required qualification,
a travel expectation, a culture signal, and so on — never a paragraph
summary or a bag of keywords. `category` (13 fixed cases, from
`responsibility` and `required_qualification` through `travel` and
`authorization`) says what kind of observation it is; `basis` says how
directly the posting supports it (`explicit`, `strongly_implied`, or
`inferred`).

**`JobAnalysisFindingEvidence` is a real hasMany child table, not an
inline column or JSON array**, because a single finding is routinely
substantiated by more than one excerpt from the same posting (a travel
requirement restated in both the "Requirements" and "About the Role"
sections is one finding with two evidence rows, not two findings). This
mirrors `CareerFact` → `Evidence` structurally, but the two are not
otherwise connected in any way — see the candidate-independence note
below.

**`requirement_strength` is nullable with exactly three real values**
(`required`, `preferred`, `not_required`) — there is no fourth "just
mentioned" value. Null does not collapse into "not required"; it means
either the finding's `category` doesn't carry a requirement strength at
all (e.g. `culture_signal`, `success_measure`), or the posting is
genuinely silent on strength for an otherwise-relevant finding.
`not_required` is reserved for postings that **explicitly disclaim** a
qualification (e.g. "ERP experience is not required") — that is real,
distinct signal from the posting simply never bringing the topic up, and
collapsing the two into one representation would lose it.

**`years_experience_min`/`years_experience_max` are a faithful
transcription, never a computed eligibility floor or ceiling.** "5+
years" is stored as `min=5.0, max=null` (a floor with no stated ceiling);
"7-10 years" is stored as `min=7.0, max=10.0`. Nothing at this layer
interprets these numbers against a candidate's actual experience — that
comparison, if it's ever built, is a future matching milestone, not
something this schema computes or asserts.

**This entire subtree is candidate-independent by design.** `JobAnalysis`,
`JobAnalysisFinding`, and `JobAnalysisFindingEvidence` hold no
relationship — direct, polymorphic, or otherwise — to `CareerFact`,
`Skill`, `Project`, `Employer`, or `Role`. A `JobAnalysis` describes what
a job posting is asking for in isolation; it says nothing about how well
any candidate matches it. Matching, scoring, and CareerFact selection are
later milestones layered on top of this data, not fields or relationships
on it.

A future `ResumeVariant` (see below) is expected to eventually reference
a specific `JobAnalysis` snapshot as the basis for a tailoring decision —
but that pointer does not exist yet, and nothing in this schema commits
to its exact shape.

## JobMatch

`JobMatch`, `JobMatchFinding`, `CareerFactMatch`, and `EducationMatch`
together are a **derived, AI-generated comparison of one `JobAnalysis`
snapshot against one `CareerProfile`'s canonical evidence** — not
canonical data itself, and not a revision to either side it compares.
Where `JobAnalysis` describes a job in isolation (candidate-independent,
see above), `JobMatch` is the first layer that actually reads
`CareerFact`/`Education` data. See `docs/job-match-contract.md` for the
exact structured-output shape this maps onto, and
`docs/job-match-generation.md` for the generation pipeline, provider
boundary, and prompt/schema version conventions.

**Each `JobMatch` row is a complete, versioned, immutable snapshot**, for
the same reasons as `JobAnalysis`: a `JobAnalysis` can accumulate zero or
more `JobMatch` runs over time, there is no `current_job_match_id`
pointer, and once created none of a `JobMatch`/`JobMatchFinding`/
`CareerFactMatch`/`EducationMatch` row's fields are ever updated in
place — a correction is a new `JobMatch` snapshot. Enforced the same way
as `JobAnalysis`: an `updating` guard on all four models, deliberately
not blocking initial creation of a snapshot's own tree.

**`JobMatchFinding` is one row per `JobAnalysisFinding` belonging to the
matched analysis, always** — including a finding with no support found
at all, so "the model omitted this one" is never indistinguishable from
"the model deliberately found nothing." Its `coverage` is a holistic
judgment about the finding as a whole (one of `supported`, `partial`,
`no_evidence`, `not_assessable`), independent from any single
`CareerFactMatch`/`EducationMatch`'s own `relationship` — several
`contextual`-only references can jointly justify `supported` even though
none alone would.

**`coverage` deliberately has no `contradicted` case.** Design considered
it and rejected it: `CareerFactType` (`Bullet`/`Metric`/`Capability`/
`Narrative`) has no "preference/constraint" case, so nothing in the
schema gives a matcher trustworthy structured grounds to assert an actual
contradiction rather than an absence. `no_evidence` and `not_assessable`
are both **epistemic, never capability**, judgments:

- **`no_evidence`** means the canonical dataset supplied to *this run*
  contains no evidence at all for the finding — nothing supplied even
  partially speaks to it, so `matches` and `education_matches` are both
  empty — never "the candidate cannot do this." A capability genuinely
  absent from the current dataset is indistinguishable, at this layer,
  from one simply never captured yet. Relevant-but-insufficient evidence
  (narrower in scope, adjacent/transferable, personal rather than
  professional, covering only part of a multi-part requirement) is
  `partial`, not `no_evidence` — see `docs/job-match-contract.md`.
- **`not_assessable`** means the finding falls outside what this matcher
  is authorized to determine from the career-history/education evidence
  domain at all — visa/citizenship/work-authorization, current physical
  location, relocation willingness, current willingness to travel,
  salary preference, start-date availability, and other forward-looking
  personal preferences/eligibility states. Past professional travel is
  relevant *context* but does not prove present willingness to travel;
  past remote work does not prove a current remote-only preference. This
  is a domain-authorization boundary, not "could some hypothetical
  freeform `CareerFact` narrative ever touch this topic" — that framing
  was considered and rejected as too meaningless given how flexible
  narrative text is.

**`CareerFactMatch.relationship` / `EducationMatch.relationship`** (the
shared `MatchRelationship` enum: `direct`, `transferable`, `contextual`)
is categorical, not ordinal — it answers *what kind* of support a
`CareerFact`/`Education` row provides, not a quality ranking from weak to
strong. This is a deliberate departure from an earlier ordinal design
(`direct`/`strong`/`supporting`), made specifically to avoid the same
miscalibration risk `JobAnalysis.basis` showed in practice: an LLM asked
to rank strength tends to drift toward a "safe middle" value regardless
of the actual case, whereas a categorical kind-of-support question has no
such gravitational pull. `direct` is anchored to the `JobAnalysisFinding`'s
own literal statement, not its broader category or label — a fact
demonstrating a different technology in the same broad category (e.g. a
different CI system than the one a finding names) is `transferable` at
most, never `direct` merely by category membership. See
`docs/job-match-contract.md` "Field notes."

**Education is a first-class matchable unit, via its own small
`EducationMatch` table — not a generic polymorphic "candidate support"
framework.** `CareerFact` and `Education` are the only two matchable
entity types, and each gets its own concrete join table
(`career_fact_matches`, `education_matches`) sharing the `MatchRelationship`
enum, rather than a single morph table standing in for both. `Skill` has
no matchable table of its own — it is never cited independently, only
ever read as part of the one `CareerFact` it's attached to. A `Skill`
attached to a fact IS legitimate evidence that this specific fact
involves that technology/capability/practice, often the only place a
fact's full technical detail is captured at all (a fact's prose
`statement` routinely omits it — see "Skills" above). What it does
**not** do is independently authorize a claim stronger than that
association — depth, duration, scale, ownership, or implementation
detail the fact's own `statement`/`metric` doesn't itself state, and it
never travels between facts: a `Skill` genuinely attached to one
`CareerFact` never authorizes a claim about a different one, even for
the same employer or role.

**`Education` has no `visibility` field, and that absence is not a grant
of default output eligibility.** `EducationMatch` isn't subject to
`CareerFact.visibility` gating simply because there's nothing to gate on
— a future resume-generation stage may consider `Education` without that
specific check, but that stage still separately decides, on its own
terms, whether a given `Education` record belongs in a given output. "No
`CareerFact`-style restriction" is not the same claim as "included by
default." This milestone's own read-only review UI displays `Education`
normally regardless, since it is an internal inspection surface, not a
resume-output surface.

**Deletion/FK behavior deliberately RESTRICTs rather than cascades on
`career_fact_matches.career_fact_id` and `education_matches.education_id`**
— the one place this subtree's deletion behavior differs from the
cascade-everywhere table above. A `JobMatch` is a historical, immutable
snapshot; if a live `CareerFact` or `Education` row it cited were later
deleted and the reference cascaded away silently, that snapshot would
lose support rows without anyone editing the `JobMatch` itself, which
would violate the same immutability guarantee the `updating` guard
exists to protect. Inspection of the existing deletion behavior (this
document's "Deletion behavior" section, and `Employer`/`Role`/`Project`'s
reassignment-on-delete hooks) found no existing pathway that deletes a
single `CareerFact`/`Education` row outside of whole-`CareerProfile`
deletion — `Employer`/`Role`/`Project` deletion explicitly *reassigns*
`CareerFact`s rather than deleting them — so RESTRICT introduces no
conflict with any existing lifecycle: an unreferenced `CareerFact`/
`Education` still deletes freely, and a `CareerProfile` with `JobMatch`
history behaves like any other row with a live RESTRICT-protected
dependent (deletion is blocked until the history is dealt with), exactly
as `CareerProfile` deletion is already blocked in other RESTRICT-adjacent
cases elsewhere in this schema. `JobMatchFinding`, `CareerFactMatch`, and
`EducationMatch` still cascade normally within a snapshot's own tree when
the `JobMatch` itself (or its parent `JobAnalysis`) is deleted — only the
cross-reference to *live* canonical data is restrictive.

**`input_snapshot` (JSON, required) freezes the exact normalized
candidate+job payload actually supplied to the provider** — the
`CandidatePayloadBuilder`/`JobPayloadBuilder` output at generation time,
byte-for-byte, distinct from `raw_response` (the validated structured
output). Together they give full bidirectional auditability: what was
asked, and what came back. A later edit to a live `CareerFact`'s
statement never retroactively changes what an already-persisted
`JobMatch`'s `input_snapshot` says was supplied. **`visibility` is the
one deliberate exception to snapshot-freezing** — a `CareerFactMatch`'s
effective visibility is always resolved *live* against the current
`CareerFact.visibility`, never frozen at generation time. This is an
intentional asymmetry: visibility is a real-time access-control policy
decision, not a historical fact about what was asked or answered, and
tightening a fact's visibility later must be respected by every
consumer, including old `JobMatch` snapshots — freezing old permissions
into a snapshot would silently defeat that.

**`coverage_rationale` and `rationale` are internal model-generated
commentary only — never canonical evidence and never approved resume
wording.** A future resume-generation stage must resolve the underlying
`CareerFact`/`Education` reference, enforce *current* visibility, and
generate its own wording; it may never copy a `JobMatch` rationale string
directly into candidate-facing output. This is a documentation-only rule
— there is no persisted flag distinguishing "safe" rationale from
"unsafe" rationale, because the rule is unconditional. In particular,
`coverage_rationale` can synthesize commentary drawn from several
`CareerFact`/`Education` references of mixed visibility at once, so it
can never be conditionally surfaced based on any one constituent
reference's visibility — it is categorically internal-only, always,
with no exception path.

**No years-of-experience arithmetic is ever computed by this layer.**
Summing or unioning `Role` date intervals to derive an implied years
figure is never performed — a fact attributed to a 4-year `Role` does not
prove 4 years of that specific skill, since the role's dates say nothing
about how much of that time actually involved the skill in question. The
matcher never states a computed years figure anywhere (not in
`coverage_rationale`, not in a `CareerFactMatch`/`EducationMatch`
`rationale`); a `JobAnalysisFinding`'s own stated
`years_experience_min`/`years_experience_max` remains visible as-is, and
raw `Role`/`Project` date context may be shown in the UI, but uncertainty
about actual duration is reflected through `coverage` (typically
`partial`), never through invented arithmetic.

## ResumeVariant

`ResumeVariant` and its full relational tree
(`ResumeVariantExperienceBullet`, `ResumeVariantBulletCitation`,
`ResumeVariantSummaryEvidence`, `ResumeVariantSkillSelection`,
`ResumeVariantEducationSelection`, `ResumeVariantTargetTermUsage`,
`ResumeVariantTargetTermUsageEvidence`) is a **derived, AI-generated,
job-tailored resume artifact built from one `JobMatch`'s candidate and
job context** — not canonical data itself, and not a revision to any
of the canonical data or `JobMatch` history it draws from. See
`docs/resume-variant-contract.md` for the exact structured-output
shapes this maps onto, and `docs/resume-variant-generation.md` for the
two-stage generation pipeline, provider boundary, and prompt/schema
version conventions. This satisfies the constraint an earlier revision
of this document anticipated but had not yet built: a `ResumeVariant`
snapshots the exact selected `CareerFact` lineage and generated wording
at generation time, never merely referencing live rows in a way a later
canonical edit could retroactively alter.

**Each `ResumeVariant` row is a complete, versioned, immutable
snapshot**, for the same reasons as `JobAnalysis`/`JobMatch`: a
`JobMatch` can inform zero or more `ResumeVariant` generations over
time, there is no `current_resume_variant_id` pointer, and once
created none of a `ResumeVariant` row or any row in its tree is ever
updated in place — a correction is a new `ResumeVariant` snapshot.
Enforced the same way as `JobAnalysis`/`JobMatch`: an `updating` guard
on all seven models, deliberately not blocking initial creation of a
snapshot's own tree.

### Pipeline

```
CareerProfile canonical data -> JobAnalysis -> JobMatch
  -> deterministic discovery preflight (optional)
  -> Resume Selection (structure/evidence/lineage/posture, no prose)
  -> Resume Wording (prose only, bounded to the approved selection)
  -> one immutable ResumeVariant snapshot
```

Two model stages, two separate purpose-specific provider contracts
(`GeneratesResumeSelection`, `GeneratesResumeWording`), reusing only
the existing shared OpenAI Responses transport
(`App\Support\OpenAIResponsesApiClient`) — no generic multi-purpose AI
infrastructure was introduced. See `docs/resume-variant-generation.md`
for the full pipeline, including why the two stages are hard-separated
(Wording's schema has no field capable of altering citations, evidence,
posture, chronology, titles, Skills, or Education — not merely
discouraged from doing so by prompt instruction).

### Resume eligibility — the boundary between canonical data and either provider call

`App\Support\ResumeVariant\ResumeEligibility` is the single,
deterministic boundary applied once, upstream of both provider calls,
never left to prompt instruction alone:

- **Eligible**: `Visibility::Public` or `Visibility::Restricted`.
- **Excluded**: `Visibility::Private`, always.
- **Project-level backstop**: a `CareerFact` attributed to a `Project`
  whose own `default_visibility` is `Private` is excluded even when the
  fact's own visibility is more permissive — protecting the case
  "Verification vs. visibility" above anticipated (a project whose
  existence itself must stay non-exposed) but that, before this
  milestone, no consumer actually enforced.
- **`Education` carries no `visibility` field and is never gated
  here** — consistent with the JobMatch section above: absence of a
  restriction is not a grant of default inclusion. Whether a given
  `Education` record appears in a given resume remains a separate
  Resume Selection decision on its own merits.

This is the layer the blocking visibility-curation review (commit
`578caab`, reclassifying seven granular Transaction Remediation
`CareerFact`s from `Restricted` to `Private`) exists to feed
correctly — those seven facts are excluded from every `ResumeVariant`
generation from that commit forward, while the conservative "32,000+"
headline figure (`rocketgate-transaction-remediation-total-corrected`)
remains `Restricted` and eligible.

**`JobMatch` is annotation layered on top of each eligible fact, never
a recall ceiling.** Resume Selection sees the full resume-eligible
corpus — every `CareerFact`, `Education`, and attached `Skill` currently
eligible, not only the ones a prior `JobMatch` diagnostic run happened
to cite — with each `CareerFact` carrying zero or more
`job_match_annotations` (which findings `JobMatch` linked it to, with
what `coverage`/`relationship`) as useful signal, never a restriction.
Selection may choose evidence `JobMatch` never cited for any finding.
`JobMatchFinding.coverage_rationale`, `CareerFactMatch.rationale`, and
`EducationMatch.rationale` never enter either provider payload at
all — consistent with the JobMatch section's own rule that this
commentary is internal-only and never a source of fact for
candidate-facing output.

### `ResumeClaimPosture` — direct, qualified, capability

A target-term usage (`ResumeVariantTargetTermUsage`) belongs to one
specific location (one bullet, or the summary) and declares exactly
one of three postures (`App\Enums\ResumeClaimPosture`), deliberately
distinct from `MatchRelationship` (`direct`/`transferable`/
`contextual`) — a resume claim posture and a diagnostic match
relationship answer different questions and are never conflated:

- **`direct`** — states the target term as the candidate's own
  experience. Requires computed authorization (see "Direct-evidence
  authorization" below); a `direct` posture declared without it is
  rejected outright, never trusted from the model's own say-so.
- **`qualified`** — real evidence for a different, comparable
  technology, explicitly positioned as adjacent/transferable to the
  target term via a controlled clause (see "The qualified-clause
  mechanism" below). At most one `qualified` usage is allowed per
  bullet or summary location — a location may carry several
  `direct`/`capability` usages, but never stack more than one
  qualified comparison onto the same location.
- **`capability`** — real evidence for the underlying pattern or
  capability, without comparing to the specific target term by name at
  all. Must be persisted explicitly as its own posture value — never
  inferred from the mere absence of a `direct`/`qualified` usage for a
  given term.

A single bullet or summary may have multiple `direct`/`capability`
usages backing different target terms, all governed by the same
per-location qualified cap.

### Direct-evidence authorization

`App\Support\ResumeVariant\DirectEvidenceAuthorization::forFinding()`
computes, for one `(JobMatch, JobAnalysisFinding, term)` combination,
whether a `direct` posture claim is actually authorized — never lexical
occurrence alone, and never trusted from either provider's own
declaration; the same computation is run once (to build
`target_terminology.direct_evidence_exists`) and re-checked
independently inside `ResumeSelectionResponseValidator`. Two paths, in
priority order:

1. **Primary**: `JobMatch` already recorded a `direct`-relationship
   `CareerFactMatch` for the exact finding, citing a `CareerFact`
   attributed to real work history (`Employer`/`Role`/`Project` — not a
   bare `CareerProfile`-level attribution). Reuses `JobMatch`'s own
   already-vetted judgment rather than re-deriving it from scratch.
2. **Fallback**, for facts `JobMatch` never cited (Selection sees the
   full corpus, not just what `JobMatch` linked): any eligible,
   attributed `CareerFact` with the term as an attached canonical
   `Skill` (case-insensitive exact name match).

**The fallback path is an explicitly bounded semantic limitation, not
a redefinition of the `CareerFact`<->`Skill` pivot's global meaning**
(see "Skills" above — a `Skill` tag records topical connection, not
proof of hands-on use). This fallback does not close that gap; it is
deliberately treated as a residual risk covered by canonical-data
authoring discipline and live/human review — the same trust boundary
every other canonical-data statement already rests on — rather than by
inventing a new technology-evidence ontology or silently changing what
the `CareerFact`<->`Skill` relationship means everywhere else in this
schema. A bare `Skill` row with no supporting `CareerFact` never
authorizes a direct claim under either path; neither does a `Private`
`CareerFact`, since both paths query only through `ResumeEligibility`.

### Target terminology — v1 scope

`App\Support\ResumeVariant\TargetTerminologyBuilder` computes the set
of target terms a given `JobMatch` run makes available for posture
usages: exactly the `JobAnalysisFinding` rows with
`category = technology` whose `statement` follows the atomic
`"{Term} is ..."` shape a real `JobAnalysis` reliably produces for a
single named technology (e.g. "Azure is a cloud platform relevant to
the role."). A bundled finding naming several technologies in one
statement never matches this shape and is silently excluded from
v1 — a deliberate, documented scope limit, not a general ATS
keyword/entity extraction system. No loose `actual_technologies`
string is ever persisted anywhere in this subtree; a qualified claim's
real supporting technologies are always derivable from its
`resume_variant_target_term_usage_evidence` rows' `CareerFact`s, never
duplicated as a separately declared string.

### The qualified-clause mechanism

Exact target terminology may appear in a resume **without** direct
experience only through this one controlled mechanism — never through
either provider's own free-generated prose, under any framing. Allowed
phrases (`App\Enums\ResumeQualifiedPhrase`): `applicable_to`,
`comparable_to`, `closely_related_to`, `transferable_to`. There is
deliberately no "directly transferable to" phrase — considered and
rejected as self-contradictory framing for a claim that is, by
definition, not direct.

Mechanically: Resume Wording is given a `denylist_terms` list — every
target term **except** those with an approved `direct`-posture usage
anywhere in the variant — and its free text (every bullet and the
summary) is scanned for any denylisted term via a case-insensitive
substring check; any hit rejects the entire Wording response. The
qualifying clause itself is never written by either provider — a
deterministic renderer appends it to Wording's own generated sentence
at persistence time (`", {phrase} {term}."`), so the exact term only
ever enters final output through code, never through model-generated
text. Qualified positioning is allowed only in the Summary and
Experience bullets, **never in Skills** — Skills selection has no
posture/term/qualified fields anywhere in its schema at all (structural
absence, not a runtime check) and is rendered directly from canonical
`Skill` names/categories, direct-evidence-only.

`denylist_terms` is a blanket, variant-wide list — removing a
`direct`-posture term from it says nothing about *where* Wording
should actually use it. `direct` posture itself means the term is
authorized/desired terminology at the one location Selection approved
it for, never a requirement to emit it, and never authorization at any
other location — a separate, additive mechanism (fact-local
`direct_target_terms` guidance to Wording, plus a location-scoped
deterministic leakage check) exists specifically for this; see
`docs/resume-variant-generation.md` "Target-term location integrity"
for the full mechanism, including the real historical
`target_term_usages`/bullet-group mismatch that motivated it.

A related but distinct mechanism addresses canonical Skills rather
than target terms: the Summary is additionally given
`summary_authorized_skills`, the closed-world set of canonical Skill
names its own supplied CareerFacts actually authorize — see
`docs/resume-variant-generation.md` "Summary authorized-Skills
allow-list" for why (a recurring, stochastic qwen3.8:27b failure
naming canonical Skills genuinely visible elsewhere in the same
request but not authorized for the Summary). It is a generation-time
affordance only, computed from the same authorization
`ResumeWordingResponseValidator::assertSkillProvenance()` already
enforces — it changes nothing about what is actually authorized, and
is scoped to the Summary alone.

### Discovery preflight

`App\Support\ResumeVariant\DiscoveryPreflight::run()` is a
deterministic, no-provider-call, entirely optional step that may be
run before generation to surface a small number of possibly-missing
experiences worth confirming with the candidate. Gate: a
`JobMatchFinding` with `coverage = no_evidence` whose underlying
finding is `requirement_strength = required` (any emphasis) or
`requirement_strength = preferred` with `emphasis = high` —
`not_assessable` findings are always excluded, since they are outside
this system's authorized domain entirely, not a coverage gap. Capped
to a small fixed number, prioritized required+high, then
required+normal, then preferred+high. Question text is generated
deterministically from the finding's own `label`/`statement`, never
model-generated.

**Never persisted as workflow state, and never a direct evidence
pathway.** v1 deliberately has no discovery-response table — this is
cheap and safe to re-run, so there is nothing to keep in sync. A
candidate's "yes, I have used X" answer must always enter the
canonical `CareerFact` workflow first (as a real, verifiable,
attributed fact) before it can ever become resume evidence — a
discovery answer is never wired directly into a `ResumeVariant`,
preventing an unverified claim from skipping the same
verification/visibility discipline every other canonical fact goes
through.

### Titles, chronology, and attribution

**Chronology is always deterministic, never model-decided.** Roles are
ordered by their real, canonical `Role.start_year`/`start_month`, most
recent first — the same date fields "Role date precision" above
already establishes as canonical, never a duration Resume Selection or
Wording computes or asserts itself.

**Resume Selection never writes title text at all — it chooses a closed
`title_choice` key** (`full`, `segment_1`, `segment_2`, ...), resolved to
the real canonical string only after validation, never accepted as
model-written text. This replaced an earlier `display_title: string`
design that asked the model to reproduce the exact title/segment text
itself; live evaluation against real candidate data found that
schema-loose enough to invite drift, so the field was closed into an
enum entirely. `title_choice` legally offers `full` (the role's exact
full canonical `Role.title`) plus one `segment_N` per `"/"`-delimited,
trimmed segment of that title, in left-to-right order — a title with no
`"/"` (e.g. "Developer Support Engineer") legally offers only `full`. No
fuzzy matching, normalization, abbreviation, or fallback to `full` for
an unsupported choice exists anywhere in this path.

The `title_choice` token itself **is** expressible as a flat JSON Schema
string enum — unlike the free-text `display_title` it replaced — built
per generation as `App\Support\ResumeVariant\GenerateResumeVariant::titleChoiceKeyEnum()`:
`full` plus `segment_1` through the highest segment count any candidate
Role for this run actually has, derived from live data rather than a
fixed permanent cap. What the schema *cannot* express is that a given
key is only legal for *some* roles (a title with one `"/"` has no
`segment_2`) — that per-role legality is where deterministic validation
remains the real authority, consistent with this codebase's established
"schema does coarse structure, the deterministic validator is the real
authority" convention: `App\Support\ResumeVariant\GenerateResumeVariant::titleChoices()`
builds the authoritative `role_id => ['full' => ..., 'segment_N' => ...]`
map from live `Role` data, and `ResumeSelectionResponseValidator::assertRoleAndProjectValidity()`
rejects any `(role_id, title_choice)` pair not present in that exact
role's own map — a choice legal for one role but not the selected one
fails outright, never silently falls back to `full`. Only after that
check passes does persistence resolve the validated key to its real
string via the same map (`GenerateResumeVariant::toSelectionDraft()`) —
the model never supplies, and this path never trusts, any title text
directly. The persisted column remains `resume_variant_experience_bullets.display_title`,
unchanged: this is a wire-contract change for the Selection provider
only, not a schema change to the persisted artifact.

**Employer/Role/Project attribution is exact and immutable**, restrict-
protected the same way `career_fact_matches.career_fact_id` is: a
bullet's `employer_id`/`role_id`/`project_id` cannot silently lose
their reference if the live row is later deleted, and Resume Selection
can never combine facts across different Employers/Roles/Projects in a
way that implies the wrong one produced an outcome — each bullet's
evidence already belongs to one real `Role` (and, when applicable,
`Project`). This specifically preserves the RocketGate-GitLab vs.
personal-GitHub distinction and every other named per-employer
guardrail (see `docs/resume-variant-generation.md` and
`ResumeWordingResponseValidator`'s own docblock for the complete list)
— never silently blurred across employer boundaries by generated
prose.

### Deletion/FK behavior

Mirrors the JobMatch section's own RESTRICT-vs-CASCADE reasoning
closely, with one addition specific to this subtree's structure:

- **`resume_variants.job_match_id` restricts, not cascades** — unlike
  `JobAnalysis -> JobMatch` (where a `JobMatch` is meaningless without
  its `JobAnalysis`), a single `JobMatch` may inform several
  `ResumeVariant` generations over time (full regenerations, and a
  future wording-only regeneration reusing the same underlying match).
  `JobMatch` is a protected, reusable upstream resource here, the same
  relationship `CareerFact` has to `CareerFactMatch` — you cannot
  delete the diagnostic basis of a resume that was actually generated
  from it.
- **Every FK from this subtree into *live canonical data*
  (`CareerFact`, `Skill`, `Education`, `Employer`, `Role`, `Project`)
  restricts, not cascades** — a `ResumeVariant` is a historical,
  immutable snapshot; if a live row it cited were later deleted and the
  reference cascaded away silently, the snapshot would lose evidence
  rows without anyone editing the `ResumeVariant` itself, violating the
  same immutability guarantee the `updating` guard protects. An
  unreferenced canonical row still deletes freely.
- **`resume_variant_target_term_usages.bullet_id` is the one deliberate
  cascade in this subtree** (nullable, cascades on its parent bullet's
  deletion) — a term usage has no independent existence apart from the
  bullet it annotates (or the variant itself, for a summary-location
  usage), unlike every other FK here, which points at live canonical
  data outside the snapshot's own tree.
- Everything internal to one snapshot's own tree
  (`resume_variant_experience_bullets`, `resume_variant_bullet_citations`,
  `resume_variant_summary_evidence`, `resume_variant_skill_selections`,
  `resume_variant_education_selections`,
  `resume_variant_target_term_usages`,
  `resume_variant_target_term_usage_evidence`) cascades normally when
  the `ResumeVariant` itself (or its parent `CareerProfile`) is
  deleted — only cross-references to live canonical data outside the
  snapshot restrict. A whole-`CareerProfile` wipe that owns both a
  `CareerFact` and the `ResumeVariant` citing it succeeds cleanly
  (both cascade together via their shared `career_profile_id`
  ownership path) — this is correct, coherent behavior for a
  self-contained profile deletion, not a gap in the citation
  protection above, which still fully applies to deleting a single
  `CareerFact` in isolation while a `ResumeVariant` cites it.

### `selection_input_snapshot` / `selection_raw_response` / `wording_input_snapshot` / `wording_raw_response`

Frozen at generation time, exactly like `JobMatch`'s
`input_snapshot`/`raw_response` pair — the exact normalized payload
actually supplied to each provider, and the exact validated structured
response each provider returned. A later edit to a live `CareerFact`/
`Education`/`Skill` never retroactively changes what an
already-persisted `ResumeVariant` is understood to have been based on.
`selection_raw_response` is also the exact, reusable input a future
wording-only regeneration would need (copy it verbatim into a new row,
re-run only Stage 2) — not built in v1, but nothing in this schema
blocks it.

### Anti-redundancy is structural, not semantic

There is no one-`CareerFact`-per-bullet rule anywhere in this
subtree — the same fact may legitimately back a summary claim and one
or more bullets, or back two different bullets that each draw a
different conclusion from it. Only an *exact* duplicate bullet-group
evidence set (the same set of `career_fact_keys`, order-independent,
across two bullet groups) is rejected, along with obvious exact
structural duplicates. There is no semantic-similarity/dedup NLP in
v1 — deliberately, to avoid rejecting two bullets a human reviewer
would recognize as legitimately distinct despite sharing evidence.

### UI scope (v1)

A read-only `ResumeVariant` detail page only — summary (with its
evidence and any target-term usages), Experience grouped by canonical
role in deterministic chronological order (with each bullet's
citations/source `CareerFact`s and target-term usages/postures/
qualified phrasing), Skills, Education, and (on the owning `JobMatch`
page) any `DiscoveryPreflight` candidates. No document editor, no
drag/drop reordering, no evidence pinning or swapping, and no PDF
preview or rendering — deliberately deferred; see
`docs/resume-variant-generation.md`.

## Deferred: future resume-artifact concepts

`JobApplication` and `ResumeFactSelection` are not implemented.
`ResumeVariant` itself, previously deferred here, is now implemented
— see "ResumeVariant" above.
