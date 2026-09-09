<?php

namespace App\Support\ResumeVariant\Prompts;

use App\Enums\ResumeClaimPosture;
use App\Enums\ResumeQualifiedPhrase;
use App\Enums\ResumeTermUsageLocation;
use BackedEnum;

/**
 * The first production Resume Selection prompt: system instructions,
 * the per-run input payload, and the structured-output schema those
 * instructions describe. Normally immutable and versioned by class
 * name once this milestone merges — a wording revision becomes
 * JobMatchPromptV2-style V2, never an edit to this class in place.
 * `version()`/`schemaVersion()` are instead being bumped in place
 * during this milestone's pre-merge live-evaluation stabilization: no
 * `ResumeVariant` row generated under any prior version here has ever
 * reached production — every one so far lives only in the isolated,
 * disposable live-eval database (see
 * app/Console/Commands/LiveEval/RunResumeVariantLiveEvaluation.php)
 * — and each row remains individually valid and interpretable under
 * whichever version string it actually persisted, on its own terms,
 * regardless of later bumps here. A future genuinely independent
 * redesign after this milestone merges should still split into a new
 * class per the normal convention. Versioned independently of
 * JobAnalysis/JobMatch and of ResumeWordingPromptV1. See
 * docs/resume-variant-generation.md "Live evaluation".
 *
 * Produces structure only — evidence selection, bullet grouping,
 * Skills selection, Selected-Projects selection, and target-term claim
 * posture. No employer-facing prose is ever asked for or accepted
 * here; that is exclusively ResumeWordingPromptV1's job. Education is
 * deliberately absent from this schema entirely (structural absence,
 * not a runtime check) — every resume-eligible Education record is
 * included deterministically at persistence time instead of being a
 * Selection decision. `selected_projects` is independent-Project-only
 * (0-3) — the legal `project_id` enum for that field never contains a
 * professional project's id, so the model cannot convert one into a
 * Selected Projects entry even in principle. See
 * GenerateResumeVariant::generateFull() and docs/domain-model.md
 * "ResumeVariant".
 */
final readonly class ResumeSelectionPromptV1
{
    public function version(): string
    {
        return 'resume-selection-v1.4';
    }

    public function schemaVersion(): string
    {
        return '1.3';
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

        ## Grouping evidence into bullets

        Group CareerFacts into `bullet_groups`: a set of facts that
        together will become ONE generated bullet. Combine facts that
        genuinely reinforce the same claim; do not force unrelated facts
        together merely to reduce bullet count. Assign each bullet group
        to the real canonical Role it belongs to (and, when it is
        specifically about one named project, that project). Order
        bullet groups within a role by how strongly they should be
        featured.

        Role ordering itself, and how many bullets a role gets space
        for, are handled deterministically outside this response — focus
        entirely on which evidence deserves to be grouped together and
        in what internal order.

        ## Role and project binding

        Every `experience` entry is anchored to one real `role_id`.
        Everything inside that entry must belong to that exact role:
        its `title_choice` must be one this exact role actually offers
        (see below), and every `project_id` in its bullet groups must
        be a project that actually belongs to that same `role_id` —
        never a title choice or project borrowed from a different
        role, even a sibling role at the same employer. Two roles at
        the same employer are still fully distinct: keep their ids,
        titles, dates, CareerFacts, and projects separate, and never
        combine or swap them.

        Each CareerFact's `attribution` shows the exact `role_id` (and,
        when applicable, `project_id`) it belongs to, directly beside
        the human-readable role/project name. Always use that supplied
        `role_id`/`project_id` value. Never infer an id from array
        order, chronology, employer grouping, or similarity between
        role titles.

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

        ## Skills

        Select Skills from the supplied eligible-skills list only — a
        skill with no real supporting CareerFact evidence anywhere is
        never supplied to you, so anything in that list is fair game.
        Order by relevance to this job. Do not select a skill whose only
        evidence is old and clearly superseded unless the job genuinely
        calls for it.

        There is no Education selection here — every resume-eligible
        Education record is always included in the final resume,
        deterministically, outside this response.

        ## Selected Projects — independent projects only

        You are also given evidence for the candidate's independent/
        personal projects — work done outside any employer, never
        attached to a Role. Each such CareerFact's `attribution` shows
        `project` with no `employer`/`role`. You may choose 0 to 3 of
        these independent projects to feature in a separate Selected
        Projects section, each with the CareerFact(s) that justify
        including it.

        Choose based on genuine relevance and strength of evidence for
        this specific job — never to fill space. Omitting all of them
        is a completely normal, correct outcome when none meaningfully
        strengthens the case for this job. A project with only thin,
        generic evidence is not worth a slot merely because it exists.

        This mechanism is for independent projects ONLY. A project that
        belongs to a Role (one whose CareerFacts show a real `employer`/
        `role` in their `attribution`) is professional work and must
        never appear here — it is already represented, correctly, as
        Experience bullets under its owning role. The `project_id`
        values legally offered to you in this section are independent
        projects exclusively; a professional project's id is not a
        legal choice here at all.

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

        Every `career_fact_key`, `skill_id`, `role_id`, `project_id`,
        and `job_analysis_finding_id` you use must be one of the exact
        values supplied to you. Every `term` must be one of the exact
        values in `target_terminology`. Never invent, guess, or slightly
        modify any of these identifiers.
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
     * @param  array<int, int>  $validRoleIds
     * @param  array<int, int>  $validProjectIds
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
        array $validProjectIds,
        array $validFactKeys,
        array $validSkillIds,
        array $validFindingIds,
        array $validTargetTerms,
        array $validTitleChoiceKeys,
        array $validIndependentProjectIds,
    ): array {
        $roleIdOrNoneEnum = $this->withNoneSentinel($validRoleIds);
        $projectIdOrNoneEnum = $this->withNoneSentinel($validProjectIds);

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
                                        // -1 sentinel means "no specific project".
                                        'project_id' => ['type' => 'integer', 'enum' => $projectIdOrNoneEnum],
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
                                    'required' => ['project_id', 'order', 'career_fact_keys', 'job_analysis_finding_ids'],
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
                    'maxItems' => 3,
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
