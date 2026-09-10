<?php

namespace App\Support\ResumeVariant\Prompts;

/**
 * The first production Resume Wording prompt: system instructions, the
 * per-run input payload, and the structured-output schema. Immutable
 * and versioned by class name, versioned independently of
 * ResumeSelectionPromptV1 and of JobAnalysis/JobMatch's own prompts.
 * See docs/resume-variant-generation.md.
 *
 * Receives ONLY what Selection already approved — the exact bullet
 * groups, their real canonical evidence, and the deny list of target
 * terms that must never appear in free-generated text. Produces prose
 * only: a summary, for each approved bullet group its text, and for
 * each approved Selected Project exactly one bullet's text. It has no
 * path to alter citation lineage, selected evidence, claim posture,
 * chronology, titles, Skills, Education, which Projects are selected,
 * technology names, or URLs — those fields simply do not exist in
 * this stage's schema. See docs/domain-model.md "ResumeVariant" ->
 * "Selected Projects".
 */
final readonly class ResumeWordingPromptV1
{
    public function version(): string
    {
        return 'resume-wording-v1.2';
    }

    public function schemaVersion(): string
    {
        return '1.1';
    }

    public function systemPrompt(): string
    {
        return <<<'PROMPT'
        You are writing the actual employer-facing wording for one
        tailored resume, strictly from evidence that has already been
        selected and approved for you. You do not choose what to
        include — that decision has already been made. Your job is
        wording only.

        ## What you produce

        For each supplied bullet group, write exactly one bullet
        sentence describing what it demonstrates, grounded only in the
        CareerFact statements/metrics supplied for that specific group.
        Write one professional summary synthesizing the candidate's
        strongest fit for this job, grounded only in the summary
        evidence supplied. For each supplied Selected Project, write
        exactly one concise bullet sentence describing what it
        demonstrates, grounded only in that project's own supplied
        CareerFacts — never technologies, users, scale, or outcomes
        beyond what those specific facts state. Do not name specific
        technologies in a Selected Project bullet unless a supplied
        CareerFact statement itself names them; the technology list
        shown alongside your bullet is generated separately and
        deterministically, not by you.

        Write with confidence. State plainly and specifically what the
        evidence supports — do not hedge, qualify, or under-sell a real
        accomplishment out of excess caution. Do not pad sentences with
        vague filler. Prefer concrete, specific language over generic
        praise.

        ## Experience bullet length and focus

        Target approximately 20-28 words per Experience bullet. A soft
        maximum is 32 words. Go up to approximately 36 words only when
        preserving an unusually important technical distinction or a
        quantified result genuinely requires it — not as a default.

        Each bullet should carry one principal accomplishment, system,
        or theme. Do not enumerate every supported implementation
        detail merely because the cited facts permit it, and do not
        combine unrelated facts solely to maximize how much of the
        evidence you use. When a bullet group's evidence supports more
        than one noteworthy detail, preserve the strongest
        differentiating technical detail and/or the strongest supported
        quantified result, and let the rest go unstated. Concise does
        not mean generic: a short bullet must still be specific and
        concrete, never vague filler standing in for detail you cut.

        This same length/focus guidance applies to a Selected Project's
        one bullet.

        ## Verb variation

        Within a single Role, vary how bullets open. Do not start
        consecutive bullets with the same verb, and do not start a
        large majority of one Role's bullets with the same verb (a
        repetitive run of "Built ..." across most of a Role's bullets
        is exactly what this rule prevents). Vary openings naturally,
        only when the actual work supports the verb you choose — never
        swap in a stronger-sounding verb than the evidence describes.
        Appropriate verbs may include, when truthful to the selected
        evidence: Engineered, Developed, Designed, Implemented,
        Created, Automated, Delivered, Modernized, Integrated, Led,
        Partnered, Collaborated. These are examples of the kind of
        variety expected, not a fixed list to rotate through
        mechanically, and not an instruction to inflate ownership —
        the sole-credit/ownership guardrails below remain authoritative
        over what any verb may imply about who did the work.

        ## Summary

        Target approximately 35-50 words. Refocus the summary on
        recruiter positioning rather than compressing as many
        impressive facts as will fit. In order: (1) what kind of
        candidate this is, (2) what kinds of systems/problems they
        work on, (3) target-relevant technical/capability breadth, and
        only then (4) concise impact or differentiation where it adds
        real signal. For a full-stack-shaped job, the summary should
        unmistakably read as a senior full-stack/software candidate
        before it dives into a specialized example such as transaction
        remediation. The summary does not need to contain the resume's
        largest metric — avoid dense constructions that list several
        highly specific systems in one sentence merely because they
        are all supported by evidence.

        ## Never invent, never compute, never overstate

        - Never state or imply a technology, scope, outcome, or
          accomplishment that isn't directly present in the supplied
          CareerFact statements or Metrics for that specific bullet/
          summary.
        - If a Metric includes a `guardrail`, treat it as a hard
          constraint on how that figure may be characterized — never
          restate a guarded figure in the way its own guardrail
          forbids. Respect `scope_note` exactly; never generalize a
          figure beyond the scope its own data states.
        - Never state a computed candidate-years figure, for any
          bullet or the summary. You may reference an employer/role's
          own stated date range as context, but never sum, average, or
          otherwise calculate a duration and present it as established.
        - Never combine facts across different Employers/Roles/Projects
          in a way that implies the wrong one used a technology or
          produced an outcome — each bullet's evidence already belongs
          to one real Role (and, when applicable, Project); do not
          import technology or scope from a different context.

        ## The target-term deny list — hard boundary

        You are given a `denylist_terms` list. None of these exact terms
        (or an obvious variant of one) may appear anywhere in your free
        text — not in a bullet, not in the summary, not in a Selected
        Project bullet — under any framing, including a qualifying or
        comparative one. These terms may only ever enter the final
        resume through a separate, deterministic mechanism outside your
        control. Writing a denylisted term yourself, even to say the
        candidate does NOT have it, is a violation — simply do not
        mention it at all.

        ## Structure

        Respond with exactly one summary, one bullet entry per supplied
        bullet group (identified by role and index), and one bullet
        entry per supplied Selected Project (identified by project id),
        in any order, with no omissions and no duplicates.
        PROMPT;
    }

    /**
     * @param  array<string, mixed>  $approvedSelection  The exact evidence Stage 1 approved, re-hydrated with real canonical text.
     * @param  array<int, string>  $denylistTerms
     */
    public function userPrompt(array $approvedSelection, array $denylistTerms): string
    {
        $selectionJson = json_encode($approvedSelection, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $denylistJson = json_encode($denylistTerms, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return "--- Approved selection (evidence, structure, exactly what to word) ---\n"
            ."{$selectionJson}\n"
            ."--- End of approved selection ---\n\n"
            ."--- denylist_terms (must never appear in your free text) ---\n"
            ."{$denylistJson}\n"
            .'--- End of denylist_terms ---';
    }

    /**
     * @param  array<int, int>  $validRoleIds  The roles Selection actually included.
     * @param  array<int, int>  $validSelectedProjectIds  The independent Projects Selection actually included.
     * @return array<string, mixed>
     */
    public function jsonSchema(array $validRoleIds, array $validSelectedProjectIds): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'summary' => ['type' => 'string'],
                'experience' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'role_id' => ['type' => 'integer', 'enum' => $validRoleIds === [] ? [-1] : $validRoleIds],
                            'bullets' => [
                                'type' => 'array',
                                'items' => [
                                    'type' => 'object',
                                    'properties' => [
                                        'bullet_group_index' => ['type' => 'integer'],
                                        'text' => ['type' => 'string'],
                                    ],
                                    'required' => ['bullet_group_index', 'text'],
                                    'additionalProperties' => false,
                                ],
                            ],
                        ],
                        'required' => ['role_id', 'bullets'],
                        'additionalProperties' => false,
                    ],
                ],
                'selected_projects' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'project_id' => ['type' => 'integer', 'enum' => $validSelectedProjectIds === [] ? [-1] : $validSelectedProjectIds],
                            // Exactly one bullet's text per Selected Project in
                            // v1 — a single string, not a bullets[] array, so
                            // there is nothing to sub-group or index. See
                            // docs/domain-model.md "ResumeVariant" -> "Selected
                            // Projects" for why this stays extensible without
                            // becoming a JSON blob if the cap is ever raised.
                            'text' => ['type' => 'string'],
                        ],
                        'required' => ['project_id', 'text'],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            'required' => ['summary', 'experience', 'selected_projects'],
            'additionalProperties' => false,
        ];
    }
}
