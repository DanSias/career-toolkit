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
 * name once real ResumeVariant history exists — a wording revision
 * becomes JobMatchPromptV2-style V2, never an edit to this class in
 * place. `version()` was bumped in place (not split into a V2 class)
 * during this milestone's pre-merge live-evaluation stabilization,
 * since zero ResumeVariant rows have ever been persisted under
 * 'resume-selection-v1' — there is no existing history to protect from
 * reinterpretation yet. `version()` return value tracks each revision
 * from here; a future genuinely independent redesign after real
 * history exists should still split into a new class per the normal
 * convention. Versioned independently of JobAnalysis/JobMatch and of
 * ResumeWordingPromptV1. See docs/resume-variant-generation.md "Live
 * evaluation".
 *
 * Produces structure only — evidence selection, bullet grouping,
 * Skills/Education selection, and target-term claim posture. No
 * employer-facing prose is ever asked for or accepted here; that is
 * exclusively ResumeWordingPromptV1's job.
 */
final readonly class ResumeSelectionPromptV1
{
    public function version(): string
    {
        return 'resume-selection-v1.1';
    }

    public function schemaVersion(): string
    {
        return '1.0';
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

        You are given every currently eligible CareerFact, Education
        record, and Skill in the candidate's canonical profile — not
        only the ones a prior JobMatch diagnostic run happened to link
        to a specific job requirement. Each CareerFact carries zero or
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
        its `display_title` must be valid for that same `role_id` (see
        below), and every `project_id` in its bullet groups must be a
        project that actually belongs to that same `role_id` — never a
        title or project borrowed from a different role, even a
        sibling role at the same employer. Two roles at the same
        employer are still fully distinct: keep their ids, titles,
        dates, CareerFacts, and projects separate, and never combine or
        swap them.

        Each CareerFact's `attribution` shows the exact `role_id` (and,
        when applicable, `project_id`) it belongs to, directly beside
        the human-readable role/project name. Always use that supplied
        `role_id`/`project_id` value. Never infer an id from array
        order, chronology, employer grouping, or similarity between
        role titles.

        ## Display titles

        For each selected role, choose a `display_title`: either the
        role's full canonical title verbatim, or one of its exact
        "/"-delimited segments verbatim — never a new or reworded
        string. For example, a canonical title "Senior Engineer /
        Technical Lead" may be shown in full, or as just "Senior
        Engineer", or as just "Technical Lead" — never as an unrelated
        string like "Lead Software Engineer". Choose whichever framing
        best fits this specific job.

        ## Skills and Education

        Select Skills from the supplied eligible-skills list only — a
        skill with no real supporting CareerFact evidence anywhere is
        never supplied to you, so anything in that list is fair game.
        Order by relevance to this job. Do not select a skill whose only
        evidence is old and clearly superseded unless the job genuinely
        calls for it. Select Education records the same way — on their
        own merits for this job, independent of whether JobMatch ever
        cited them.

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

        Every `career_fact_key`, `education_id`, `skill_id`, `role_id`,
        `project_id`, and `job_analysis_finding_id` you use must be one
        of the exact values supplied to you. Every `term` must be one of
        the exact values in `target_terminology`. Never invent, guess, or
        slightly modify any of these identifiers.
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
     * @param  array<int, int>  $validEducationIds
     * @param  array<int, int>  $validSkillIds
     * @param  array<int, int>  $validFindingIds
     * @param  array<int, string>  $validTargetTerms
     * @return array<string, mixed>
     */
    public function jsonSchema(
        array $validRoleIds,
        array $validProjectIds,
        array $validFactKeys,
        array $validEducationIds,
        array $validSkillIds,
        array $validFindingIds,
        array $validTargetTerms,
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
                'education_selection' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'education_id' => ['type' => 'integer', 'enum' => $this->nonEmptyIntEnum($validEducationIds)],
                            'order' => ['type' => 'integer'],
                        ],
                        'required' => ['education_id', 'order'],
                        'additionalProperties' => false,
                    ],
                ],
                'experience' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'role_id' => ['type' => 'integer', 'enum' => $this->nonEmptyIntEnum($validRoleIds)],
                            'display_title' => ['type' => 'string'],
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
                        'required' => ['role_id', 'display_title', 'bullet_groups'],
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
            'required' => ['summary_evidence', 'skills', 'education_selection', 'experience', 'target_term_usages'],
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
