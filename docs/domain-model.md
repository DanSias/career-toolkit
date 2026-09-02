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
target is resolved by walking existing relationships — never by a stored
`career_profile_id` on `Role`/`Project`:

```text
CareerProfile → itself
Employer      → employer.career_profile_id
Role          → role.employer.career_profile_id
Project       → project.role.employer.career_profile_id
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

`CareerProfile` is treated as the true aggregate root: deleting it deletes
everything it owns, with no special handling needed.

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

## Deferred: future resume-artifact concepts

`JobApplication`, `ResumeVariant`, and `ResumeFactSelection` are not
implemented. One constraint they'll need to satisfy is captured here so it
isn't lost: a generated/submitted `ResumeVariant` must snapshot the exact
selected `CareerFact` content and wording _at generation time_, rather
than merely reference live `CareerFact` rows by ID — so a later correction
to a canonical fact can never retroactively alter a resume that was
already submitted somewhere. Nothing in the schema above blocks this; it's
simply not built yet.
