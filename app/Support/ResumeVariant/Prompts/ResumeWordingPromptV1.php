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
 * only: a summary and, for each approved bullet group, its text. It
 * has no path to alter citation lineage, selected evidence, claim
 * posture, chronology, titles, Skills, or Education — those fields
 * simply do not exist in this stage's schema.
 */
final readonly class ResumeWordingPromptV1
{
    public function version(): string
    {
        return 'resume-wording-v1';
    }

    public function schemaVersion(): string
    {
        return '1.0';
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
        evidence supplied.

        Write with confidence. State plainly and specifically what the
        evidence supports — do not hedge, qualify, or under-sell a real
        accomplishment out of excess caution. Do not pad sentences with
        vague filler. Prefer concrete, specific language over generic
        praise.

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
        text — not in a bullet, not in the summary — under any framing,
        including a qualifying or comparative one. These terms may only
        ever enter the final resume through a separate, deterministic
        mechanism outside your control. Writing a denylisted term
        yourself, even to say the candidate does NOT have it, is a
        violation — simply do not mention it at all.

        ## Structure

        Respond with exactly one summary and one bullet entry per
        supplied bullet group (identified by role and index), in any
        order, with no omissions and no duplicates.
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
     * @return array<string, mixed>
     */
    public function jsonSchema(array $validRoleIds): array
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
            ],
            'required' => ['summary', 'experience'],
            'additionalProperties' => false,
        ];
    }
}
