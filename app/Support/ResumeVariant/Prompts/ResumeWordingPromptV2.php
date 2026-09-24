<?php

namespace App\Support\ResumeVariant\Prompts;

/**
 * The second production Resume Wording prompt — a new class rather than
 * an in-place bump of ResumeWordingPromptV1's `resume-wording-v1.3`
 * version string, because that string has already reached real,
 * persisted production use (two real ResumeVariant rows) and the
 * established immutable-prompt convention only permits an in-place bump
 * when a version has never been persisted. See
 * ResumeSelectionPromptV2's own docblock for the same reasoning applied
 * there. `resume-wording-v2` itself has never been persisted (verified
 * against the real dev database before each of the two changes below),
 * so both were made in place, on this same class, rather than each
 * requiring its own new class.
 *
 * Two semantic changes from V1, both in the `## Summary` and
 * `## Never invent, never compute, never overstate` sections
 * respectively — everything else (userPrompt(), jsonSchema(), every
 * other system-prompt section) is byte-identical to
 * ResumeWordingPromptV1:
 *
 * 1. One clarifying sentence in `## Summary`, added after the first
 *    live qwen3.8:27b Resume Wording evaluation showed a generated
 *    summary borrowing "React" and "Laravel" — technologies genuinely
 *    true of the candidate and genuinely present elsewhere in the same
 *    request (attached to other roles'/projects' own supplied
 *    CareerFacts) — even though none of the four facts actually
 *    supplied as summary evidence authorized them. The new sentence
 *    names the exact failure mechanism (same-request, different-
 *    location evidence) rather than restating the pre-existing, more
 *    general "never invent" rule elsewhere in this prompt, which
 *    evidently wasn't enough on its own for the model to connect to
 *    the `## Summary` section's own "technical/capability breadth"
 *    language.
 * 2. One new bullet in `## Never invent, never compute, never
 *    overstate`, added after a qualitative review of two independent
 *    live qwen3.8:27b generations found the same recurring pattern: a
 *    guardrail-sensitive quantified figure placed immediately adjacent
 *    to a different quantified claim in one sentence (e.g. "...saving
 *    20+ hours weekly across teams tracking $25M+ in annual marketing
 *    spend" — grammatically correct and not a guardrail violation on
 *    a careful read, but a real, avoidable fast-read ambiguity about
 *    which figure the sentence is actually claiming). The new bullet
 *    generalizes this into a standing rule about keeping any two
 *    quantified figures unambiguously attached to their own outcome —
 *    it does not name the specific fact, figure, or CareerFact key
 *    that motivated it.
 *
 * See docs/resume-variant-generation.md "Skill provenance" and
 * "Metric-quantity separation" for the full investigations behind
 * each.
 *
 * schemaVersion() is unchanged (`1.1`) — the JSON schema itself is
 * byte-identical to V1; only prompt text changed. See
 * ResumeWordingPromptV1's own docblock for the full contract this
 * prompt implements — it has not been repeated here beyond what
 * changed.
 */
final readonly class ResumeWordingPromptV2
{
    public function version(): string
    {
        return 'resume-wording-v2';
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

        When several cited facts describe stages, safeguards, or
        mechanics of one system (for example, separate facts for
        planning, execution, verification, recovery, and auditing
        within one pipeline), synthesize the most differentiating
        details into a coherent accomplishment rather than listing
        every supported step. Prefer the few details that best
        establish technical difficulty, safety, scale, or outcome. This
        synthesis is about how you compress those facts into prose —
        it must never cause a guardrail-bearing detail or a Metric's
        own required scope/qualifier to disappear; whenever you do
        state a guarded figure, it must still carry exactly the framing
        its guardrail and scope_note require.

        This same length/focus guidance applies to a Selected Project's
        one bullet.

        ## Verb variation

        Within a role containing multiple bullets, do not begin
        consecutive bullets with the same verb when a truthful, natural
        alternative is available. Do not mechanically rotate synonyms
        merely to satisfy this preference — only vary when the actual
        work supports the different verb you choose, never swap in a
        stronger-sounding verb than the evidence describes, and never
        force a variation so unnatural that it misdescribes the work.
        Also do not start a large majority of one role's bullets with
        the same verb (a repetitive run of "Built ..." across most of a
        role's bullets is exactly what this prevents). Appropriate
        verbs may include, when truthful to the selected
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
        real signal. Draw (3)'s technical/capability breadth only from
        the summary evidence supplied above — never from a technology
        or capability that is only supported by evidence supplied to a
        different bullet or Selected Project elsewhere in this same
        request, even when it is genuinely true of the candidate. For
        a full-stack-shaped job, the summary should
        unmistakably read as a senior full-stack/software candidate
        before it dives into a specialized example such as transaction
        remediation. The summary does not need to contain the resume's
        largest metric — avoid dense constructions that list several
        highly specific systems in one sentence merely because they
        are all supported by evidence.

        Prefer direct candidate-positioning language over broad
        self-sufficiency claims. Do not generalize project-specific
        independent ownership into a career-wide statement such as
        "independently delivers production systems" unless the
        selected evidence supports independence at that broader scope
        and the distinction materially helps the target positioning.
        This does not prohibit supported project-level wording such as
        "independently designed/implemented" where the selected
        CareerFacts explicitly authorize it — the concern is only
        stretching a narrower, project-scoped claim into a sweeping
        career-wide one the evidence doesn't actually establish.

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
        - When a bullet or the summary states more than one quantified
          figure, keep each figure clearly attached to the single
          outcome or scope it actually measures. Never phrase two
          figures so closely together that a reader could plausibly
          misread a guardrail-sensitive figure as modifying the other
          figure's claim instead of its own.
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
