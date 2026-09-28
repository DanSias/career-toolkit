<?php

namespace App\Support\ResumeVariant\Prompts;

use App\Enums\ResumeClaimPosture;
use App\Enums\ResumeQualifiedPhrase;
use App\Enums\ResumeTermUsageLocation;
use App\Support\ResumeVariant\ResumeSelectionResponseValidator;
use BackedEnum;

/**
 * A narrow, text-only revision of ResumeSelectionPromptV3 — a new class,
 * never an in-place edit, because `resume-selection-v3` is already the
 * real, persisted `selection_prompt_version` on a real ResumeVariant
 * (the first successful TRM Labs generation); editing V3's own prompt
 * text in place would silently misrepresent what was actually sent to
 * produce that already-persisted row. `schemaVersion()` stays `'2.0'`:
 * the decoded response SHAPE is byte-identical to V3 (no field added,
 * removed, or renamed) — mirroring exactly how V2 kept V1's
 * schemaVersion unchanged for its own prompt-text-only revision.
 *
 * **What changed and why:** a read-only quality audit of that real TRM
 * Labs generation (ResumeVariant #3) found Selection V3 fundamentally
 * sound — no demonstrably weaker fact displacing a stronger one, no
 * poor evidence allocation, strong coverage of the job's most important
 * findings — but one specific, narrow under-selection pattern: V3's
 * system prompt already tells the model that a CareerFact's
 * `job_match_annotations` are "useful signal about job relevance"
 * (see V3's own "full corpus is available" section, unchanged below),
 * but never distinguishes an exceptionally strong annotation
 * (`relationship: direct` + the linked finding's
 * `requirement_strength: required` + `emphasis: high`) from an
 * ordinary one. In the real audited run this let a CareerFact
 * (`well-prompted-what-it-is`) carrying exactly that combination — one
 * of only 3 eligible independent Projects, legally selectable — go
 * unselected (`selected_projects: []`) even though nothing deterministic
 * (MAX_SELECTED_PROJECTS, the Skills/Experience budgets) stood in the
 * way. The same audit found truthful, eligible Skills tied to already-
 * selected evidence (e.g. domain-specific Skills backing an Experience
 * bullet Selection had already chosen to include) went unselected too,
 * and that the prompt had no dedicated guidance for `summary_evidence`
 * at all — a schema field with zero accompanying instruction on how to
 * choose or how many facts to combine, which in the audited run led to
 * a single, technically-novel fact crowding out the job's actual
 * strongest-matched requirement theme.
 *
 * This revision adds exactly three narrow instruction blocks addressing
 * those three findings — an elevated-signal callout (applying uniformly
 * to Experience, Skills, and Selected Projects, since all three read
 * from the same annotated CareerFact corpus), a Skills cross-reference
 * sentence, and a new dedicated Summary evidence section — and changes
 * nothing else. Deliberately NOT an unconditional inclusion rule: each
 * addition is phrased as a strong candidate/signal to actively check
 * for, still weighed against the existing budgets, redundancy, and
 * relevance judgment V3 already exercises correctly. No JobAnalysis,
 * JobMatch, ResumeEligibility, target-terminology, Wording, or
 * provenance/attribution behavior is touched by this revision — see
 * docs/resume-variant-generation.md "Design boundary: selection vs.
 * provenance" for why that boundary is unaffected by evidence-priority
 * instruction text.
 */
final readonly class ResumeSelectionPromptV4
{
    public function version(): string
    {
        return 'resume-selection-v4';
    }

    public function schemaVersion(): string
    {
        return '2.0';
    }

    public function systemPrompt(): string
    {
        return <<<'PROMPT'
        You are selecting which parts of a candidate's full, currently
        eligible canonical career record should appear in a resume
        tailored to one specific job, and how they should be organized.
        You are NOT writing any employer-facing wording — that is a
        separate, later step. You produce structure and decisions only.

        ## The full corpus is available to you — JobMatch is guidance, not a gate

        You are given every currently eligible CareerFact and Skill in
        the candidate's canonical profile — not only the ones a prior
        JobMatch diagnostic run happened to link to a specific job
        requirement. Education records are also included for context
        (e.g. aligning dates with early-career roles) but are never a
        decision you make — every one is included in the final resume
        automatically, outside this response. Each CareerFact carries zero or
        more `job_match_annotations` (which findings JobMatch linked it
        to, with what coverage/relationship, and the finding's own
        requirement_strength/emphasis) when they exist — treat these as
        useful signal about job relevance, never as a restriction on
        what you may select. A CareerFact with no annotations at all may
        still be exactly the right evidence to include if it
        demonstrates real impact, scope, ownership, seniority, breadth,
        technical sophistication, production responsibility, or business
        results relevant to this candidate's overall case for this job.

        ## Especially strong evidence signals

        Among a CareerFact's `job_match_annotations`, one specific
        combination deserves special attention before you finalize your
        choices: `relationship: direct` together with its linked
        finding's `requirement_strength: required` and
        `emphasis: high`. This combination means JobMatch found that
        fact demonstrates substantially the same capability the employer
        both explicitly requires and repeatedly emphasizes — an
        exceptionally strong inclusion candidate, wherever it legally
        fits (an Experience bullet group, a Selected Project, or a
        Skill it supports). Before finalizing Experience, Skills, and
        Selected Projects, actively check whether every CareerFact
        carrying this exact combination is represented somewhere in your
        selection, and normally include it when doing so materially
        strengthens coverage of that requirement.

        This is NOT an unconditional inclusion rule. Still weigh it
        against the existing budgets below, avoid duplicating evidence a
        different selected fact already tells effectively, and do not
        select irrelevant evidence merely because its annotation happens
        to be strong. In particular, never force an independent Selected
        Project into existence solely because its evidence carries this
        signal if the same underlying capability is already demonstrated,
        as well or better, through Experience — the existing "select one
        only when it adds evidence not already told effectively by
        Experience" rule below still governs that choice. When two or
        more direct/required/high candidates compete for the same
        limited space (most often the single Selected Projects slot),
        prioritize whichever adds the most marginal coverage of a
        requirement nothing else you selected already covers, backed by
        the strongest, most specific evidence — never simply whichever
        appears first in the supplied data.

        ## Optimize the story, not finding coverage

        Do not try to produce one bullet per job finding, and do not
        feel obligated to address every finding at all. A strong resume
        is a coherent argument about the candidate, not a checklist. One
        excellent, well-chosen accomplishment can simultaneously
        communicate several things at once (ownership, technical depth,
        domain experience, scale) far better than splitting it across
        several thin bullets. An accomplishment may also deserve space
        even when it maps only loosely to a specific job requirement, if
        it makes the candidate look substantially more senior or
        capable.

        ## Every resume-eligible role must appear

        Relevance controls how much space a role gets, never whether it
        appears at all. Every real, resume-eligible Role supplied to
        you — one with at least one eligible CareerFact attributed to
        it or to one of its projects — must appear in `experience` with
        at least one bullet group, even if that group is a single,
        concise bullet for an older or less job-relevant role. Do not
        omit a role merely because it maps weakly to this specific job;
        weak relevance is a reason to give it one modest bullet, never
        a reason to erase it from the candidate's employment history.
        This is checked and enforced independently — a response missing
        a resume-eligible role is rejected outright.

        ## Overall resume length — an explicit content budget

        This resume targets roughly two pages. Volume is governed by an
        explicit content budget, not by estimating rendered page length
        from prose:

        - Experience bullet groups: a TARGET of 10-12 in total, counted
          across every role combined — never a HARD MAXIMUM of 13. A
          response with more than 13 bullet groups in total is rejected
          outright, regardless of how they are distributed across roles.
        - Skills: a TARGET of 12-16 selected, the strongest and most
          relevant to this job — never a HARD MAXIMUM of 18. A response
          selecting more than 18 Skills is rejected outright.

        TARGET is the range you should normally land inside of. HARD
        MAXIMUM is a deterministic ceiling checked independently of your
        own output — approaching it is not itself a goal. When fewer,
        stronger bullets or Skills make the case as well or better,
        staying comfortably under the target is correct; padding toward
        either number to "use up" the budget is not.

        The total budget governs the resume as a whole — it is not a
        fixed per-role quota. Allocate the Experience total across roles
        by relevance to this job, strength of evidence, recency, impact,
        and overall career depth: a highly relevant, recent role may
        reasonably take several of the available bullets, while an older
        or less relevant role may take exactly the one bullet its "every
        eligible role must appear" floor requires and nothing more. This
        is still a judgment call, not an even split — decide the right
        distribution within the total budget yourself.

        ## Grouping evidence into bullets

        Group CareerFacts into `bullet_groups`: a set of facts that
        together will become ONE generated bullet. Combine facts that
        genuinely reinforce the same claim; do not force unrelated facts
        together merely to reduce bullet count. Order bullet groups
        within a role by how strongly they should be featured.

        Role ordering itself is handled deterministically outside this
        response (always reverse-chronological by role start date), but
        how many bullets each role receives is part of your selection
        responsibility, within the total budget above; nothing later
        automatically balances or caps bullet allocation.

        ## Role binding

        Every `experience` entry is anchored to one real `role_id`.
        Everything inside that entry must belong to that exact role: its
        `title_choice` must be one this exact role actually offers (see
        below), and every CareerFact cited anywhere in its bullet groups
        must genuinely belong to that exact role, whether directly or
        through one of that role's own projects — never a fact, title
        choice, or evidence borrowed from a different role, even a
        sibling role at the same employer. Two roles at the same
        employer are still fully distinct: keep their ids, titles,
        dates, and CareerFacts separate, and never combine or swap them.

        Each CareerFact's `attribution` shows the exact `role_id` (and,
        when applicable, `project`) it belongs to, directly beside the
        human-readable role/project name. Always use that supplied
        `role_id` value. Never infer an id from array order, chronology,
        employer grouping, or similarity between role titles.

        You do NOT declare which project a bullet group belongs to —
        there is no `project_id` field on a bullet group at all. Which
        project (if any) a bullet's evidence belongs to is determined
        automatically, afterward, from the real canonical attribution of
        whichever CareerFacts you selected into it — you never need to
        reason about, reconstruct, or assert that yourself. Focus
        entirely on grouping facts by what makes the strongest coherent
        claim; project scoping is handled for you.

        ## Title choice

        You do not write or abbreviate a role's title — you choose
        among its deterministic canonical representations instead. For
        each selected role, set `title_choice` to `full` (the complete
        canonical title, exactly as it appears in that role's
        CareerFacts' `attribution.role`) or to `segment_1`, `segment_2`,
        etc.: the real, literal text before/between/after that title's
        `"/"` characters, read strictly left to right (`segment_1` is
        everything before the first `"/"`, `segment_2` is what follows
        it, and so on). A title with no `"/"` has no segments at all —
        `full` is the only legal choice for that role. For example, a
        role titled "Senior Engineer / Technical Lead" legally offers
        `full`, `segment_1` (="Senior Engineer"), and `segment_2`
        (="Technical Lead") — pick whichever framing best fits this
        specific job. A choice not actually legal for the selected role
        is rejected; there is no mechanism to supply new or reworded
        title text.

        ## Summary evidence

        `summary_evidence` may include more than one CareerFact — do not
        default to a single fact merely because one feels like the most
        novel or distinctive theme. Choose whichever small combination
        gives the strongest, most representative cross-cutting
        positioning for THIS job: if the job's own single most heavily
        emphasized required qualification centers on a different theme
        than the candidate's most technically novel accomplishment,
        prefer evidence that lets the summary speak to both rather than
        spending it entirely on the more novel theme alone. Keep it
        small — this is a short positioning statement, not a second
        Experience section — but do not under-select when a second,
        complementary fact would materially improve how well the
        summary represents the candidate for this specific job.

        ## Skills

        Select Skills from the supplied eligible-skills list only — a
        skill with no real supporting CareerFact evidence anywhere is
        never supplied to you, so anything in that list is fair game.
        Order by relevance to this job. Do not select a skill whose only
        evidence is old and clearly superseded unless the job genuinely
        calls for it. Select a TARGET of 12-16 of the strongest, most
        relevant Skills — never more than the HARD MAXIMUM of 18 (see
        "Overall resume length" above). Prefer fewer, clearly relevant
        Skills over padding the list toward the maximum.

        A Skill attached to a CareerFact you have already selected
        elsewhere — especially one carrying the direct/required/high
        signal described above — is a cheap, high-value inclusion
        candidate: it costs one short list entry and reinforces evidence
        you have already judged important enough to include. Do not
        overlook an eligible Skill just because it belongs to a
        secondary theme of the resume (for example, a domain-specific
        Skill alongside a primarily technical narrative) when the
        job places real, explicit weight on that concept and evidence
        you already selected truthfully supports it.

        There is no Education selection here — every resume-eligible
        Education record is always included in the final resume,
        deterministically, outside this response.

        ## Selected Projects — independent projects only

        You are also given evidence for the candidate's independent/
        personal projects — work done outside any employer, never
        attached to a Role. Each such CareerFact's `attribution` shows
        `project` with no `employer`/`role`. You may choose 0 or 1 of
        these independent projects to feature in a separate Selected
        Projects section, with the CareerFact(s) that justify including
        it.

        Choose based on genuine relevance and strength of evidence for
        this specific job — never to fill space. Omitting it entirely
        is a completely normal, correct outcome when no independent
        project meaningfully strengthens the case for this job. A
        project with only thin, generic evidence is not worth the slot
        merely because it exists. Select one only when it adds evidence
        not already told effectively by Experience — do not select an
        independent project merely because the slot exists.

        This mechanism is for independent projects ONLY. A project that
        belongs to a Role (one whose CareerFacts show a real `employer`/
        `role` in their `attribution`) is professional work and must
        never appear here — it is already represented, correctly, as
        Experience bullets under its owning role. Unlike an Experience
        bullet group, a Selected Projects entry DOES declare its own
        `project_id` explicitly — this section is the one place a
        specific project is still a choice you make, since it is the
        entire subject of the entry, not incidental evidence scope. The
        `project_id` values legally offered to you here are independent
        projects exclusively; a professional project's id is not a
        legal choice at all.

        You choose evidence only — not wording, not which technologies
        to mention, not whether a live demo or repository link
        appears. Exactly one bullet will be generated per selected
        project from the evidence you cite, and any technology names
        shown are derived deterministically from that evidence's own
        attached Skills, not written by you.

        ## Target-term usages — claim posture

        You are given a list of `target_terminology`: specific named
        technologies this job asks for, each flagged with whether direct
        evidence already authorizes a direct claim
        (`direct_evidence_exists`). For each target term worth
        addressing, produce a `target_term_usages` entry with:

        - `posture`:
          - `direct` — ONLY when `direct_evidence_exists` is true for
            this term. States the technology as the candidate's own
            experience.
          - `qualified` — real evidence for a DIFFERENT, comparable
            technology, explicitly positioned as adjacent/transferable
            to the requested one. The requested term's literal name will
            be rendered into the resume through a controlled mechanism
            you do not write yourself — you only declare which real
            technologies support the comparison
            (`career_fact_keys`) and pick a `relationship_phrase_key`
            that fits.
          - `capability` — real evidence for the underlying pattern or
            capability, without comparing to the specific requested
            technology by name at all. Use this when a named comparison
            would overreach or simply isn't useful.
        - `location`: `bullet` (with the exact `role_id` and
          `bullet_group_index` of an already-declared bullet group) or
          `summary`.
        - `career_fact_keys`: the real evidence this specific claim
          rests on. Never empty.
        - `relationship_phrase_key`: one of the four real phrases when
          `posture` is `qualified`; otherwise `not_applicable`.

        Do not declare a `direct` posture for a term where
        `direct_evidence_exists` is false — that authorization is
        computed for you and re-checked independently; a violation is
        rejected outright. Do not force a target-term usage for every
        term in the list — most jobs will have several with no
        meaningful, defensible connection to the candidate's real
        evidence, and those should simply be left out.

        A single bullet may have multiple `direct`/`capability` usages
        but at most one `qualified` usage — do not stack more than one
        qualified comparison onto the same bullet or the summary.

        ## Do not invent anything

        Every `career_fact_key`, `skill_id`, `role_id`, `project_id`
        (Selected Projects only), and `job_analysis_finding_id` you use
        must be one of the exact values supplied to you. Every `term`
        must be one of the exact values in `target_terminology`. Never
        invent, guess, or slightly modify any of these identifiers.
        PROMPT;
    }

    /**
     * @param  array<string, mixed>  $candidatePayload  From ResumeCandidatePayloadBuilder.
     * @param  array<string, mixed>  $jobPayload  From App\Support\JobMatch\JobPayloadBuilder.
     * @param  array<int, array<string, mixed>>  $targetTerminology  From TargetTerminologyBuilder.
     */
    public function userPrompt(array $candidatePayload, array $jobPayload, array $targetTerminology): string
    {
        $candidateJson = json_encode($candidatePayload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $jobJson = json_encode($jobPayload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $termsJson = json_encode($targetTerminology, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return "--- Candidate canonical data (full eligible corpus, with JobMatch annotations where they exist) ---\n"
            ."{$candidateJson}\n"
            ."--- End of candidate canonical data ---\n\n"
            ."--- Job analysis (role context and every finding) ---\n"
            ."{$jobJson}\n"
            ."--- End of job analysis ---\n\n"
            ."--- Target terminology ---\n"
            ."{$termsJson}\n"
            .'--- End of target terminology ---';
    }

    /**
     * Byte-identical to ResumeSelectionPromptV3::jsonSchema() — this
     * revision changes prompt text only, never the decoded response
     * shape. See this class's own docblock.
     *
     * @param  array<int, int>  $validRoleIds
     * @param  array<int, string>  $validFactKeys
     * @param  array<int, int>  $validSkillIds
     * @param  array<int, int>  $validFindingIds
     * @param  array<int, string>  $validTargetTerms
     * @param  array<int, string>  $validTitleChoiceKeys  Global set of title_choice tokens offered by any candidate role ('full', plus 'segment_N' up to the highest segment count any role actually has) — per-role legality is re-checked deterministically by the validator, not expressible here.
     * @param  array<int, int>  $validIndependentProjectIds  Independent (role_id-null) Projects only — never contains a professional project's id. See docs/domain-model.md "ResumeVariant" -> "Selected Projects".
     * @return array<string, mixed>
     */
    public function jsonSchema(
        array $validRoleIds,
        array $validFactKeys,
        array $validSkillIds,
        array $validFindingIds,
        array $validTargetTerms,
        array $validTitleChoiceKeys,
        array $validIndependentProjectIds,
    ): array {
        $roleIdOrNoneEnum = $this->withNoneSentinel($validRoleIds);

        return [
            'type' => 'object',
            'properties' => [
                'summary_evidence' => [
                    'type' => 'array',
                    'items' => ['type' => 'string', 'enum' => $this->nonEmptyStringEnum($validFactKeys)],
                ],
                'skills' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'skill_id' => ['type' => 'integer', 'enum' => $this->nonEmptyIntEnum($validSkillIds)],
                            'order' => ['type' => 'integer'],
                        ],
                        'required' => ['skill_id', 'order'],
                        'additionalProperties' => false,
                    ],
                ],
                'experience' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'role_id' => ['type' => 'integer', 'enum' => $this->nonEmptyIntEnum($validRoleIds)],
                            'title_choice' => ['type' => 'string', 'enum' => $this->nonEmptyStringEnum($validTitleChoiceKeys)],
                            'bullet_groups' => [
                                'type' => 'array',
                                'items' => [
                                    'type' => 'object',
                                    'properties' => [
                                        'order' => ['type' => 'integer'],
                                        'career_fact_keys' => [
                                            'type' => 'array',
                                            'items' => ['type' => 'string', 'enum' => $this->nonEmptyStringEnum($validFactKeys)],
                                        ],
                                        'job_analysis_finding_ids' => [
                                            'type' => 'array',
                                            'items' => ['type' => 'integer', 'enum' => $this->nonEmptyIntEnum($validFindingIds)],
                                        ],
                                    ],
                                    'required' => ['order', 'career_fact_keys', 'job_analysis_finding_ids'],
                                    'additionalProperties' => false,
                                ],
                            ],
                        ],
                        'required' => ['role_id', 'title_choice', 'bullet_groups'],
                        'additionalProperties' => false,
                    ],
                ],
                'selected_projects' => [
                    'type' => 'array',
                    // Single source of truth for this ceiling — see
                    // ResumeSelectionResponseValidator::MAX_SELECTED_PROJECTS's
                    // own docblock for why it's 1, not schema-independent here.
                    'maxItems' => ResumeSelectionResponseValidator::MAX_SELECTED_PROJECTS,
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'project_id' => ['type' => 'integer', 'enum' => $this->nonEmptyIntEnum($validIndependentProjectIds)],
                            'order' => ['type' => 'integer'],
                            'career_fact_keys' => [
                                'type' => 'array',
                                'items' => ['type' => 'string', 'enum' => $this->nonEmptyStringEnum($validFactKeys)],
                            ],
                        ],
                        'required' => ['project_id', 'order', 'career_fact_keys'],
                        'additionalProperties' => false,
                    ],
                ],
                'target_term_usages' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'term' => ['type' => 'string', 'enum' => $this->nonEmptyStringEnum($validTargetTerms)],
                            'job_analysis_finding_id' => ['type' => 'integer', 'enum' => $this->nonEmptyIntEnum($validFindingIds)],
                            'posture' => ['type' => 'string', 'enum' => $this->enumValues(ResumeClaimPosture::class)],
                            'location_type' => ['type' => 'string', 'enum' => $this->enumValues(ResumeTermUsageLocation::class)],
                            // -1 sentinel pair means "location is summary, not bullet".
                            'role_id' => ['type' => 'integer', 'enum' => $roleIdOrNoneEnum],
                            'bullet_group_index' => ['type' => 'integer'],
                            'relationship_phrase_key' => ['type' => 'string', 'enum' => $this->enumValues(ResumeQualifiedPhrase::class)],
                            'career_fact_keys' => [
                                'type' => 'array',
                                'items' => ['type' => 'string', 'enum' => $this->nonEmptyStringEnum($validFactKeys)],
                            ],
                        ],
                        'required' => ['term', 'job_analysis_finding_id', 'posture', 'location_type', 'role_id', 'bullet_group_index', 'relationship_phrase_key', 'career_fact_keys'],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            'required' => ['summary_evidence', 'skills', 'experience', 'selected_projects', 'target_term_usages'],
            'additionalProperties' => false,
        ];
    }

    /**
     * @param  class-string<BackedEnum>  $enum
     * @return array<int, string>
     */
    private function enumValues(string $enum): array
    {
        return array_map(fn (BackedEnum $case): string => (string) $case->value, $enum::cases());
    }

    /**
     * A profile/analysis with zero valid values for some field would
     * otherwise produce an empty `enum: []` constraint — unsatisfiable
     * and risky to send a structured-output compiler. Substituting a
     * sentinel keeps the schema well-formed; deterministic validation
     * independently rejects the sentinel too, same as any other
     * out-of-set value. Mirrors JobMatchPromptV1::nonEmptyEnum().
     *
     * @param  array<int, string>  $values
     * @return array<int, string>
     */
    private function nonEmptyStringEnum(array $values): array
    {
        return $values === [] ? ['__none__'] : $values;
    }

    /**
     * @param  array<int, int>  $values
     * @return array<int, int>
     */
    private function nonEmptyIntEnum(array $values): array
    {
        return $values === [] ? [-1] : $values;
    }

    /**
     * @param  array<int, int>  $ids
     * @return array<int, int>
     */
    private function withNoneSentinel(array $ids): array
    {
        return [...$ids, -1];
    }
}
