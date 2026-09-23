<?php

namespace App\Support\JobMatch\Prompts;

use App\Enums\JobMatchCoverage;
use App\Enums\MatchRelationship;
use BackedEnum;

/**
 * Revises JobMatchPromptV1's `no_evidence` coverage definition —
 * everything else (the `partial`/`supported`/`not_assessable`
 * definitions, relationship semantics, "do not invent anything",
 * years-of-experience handling, contradiction preservation, rationale
 * rules, structure requirement, user prompt, and JSON schema) is
 * unchanged. Immutable and versioned by class name, exactly like V1 —
 * see docs/job-match-generation.md for the version-bump rules.
 *
 * Motivated by a live qwen3.8:27b evaluation (see the local-Ollama-
 * provider investigation): two findings came back with
 * `coverage: no_evidence` alongside a real, cited CareerFact whose own
 * rationale argued the evidence existed but was insufficient (personal
 * rather than professional, and covering only part of a multi-part
 * requirement) — internally consistent with `partial`, but not with
 * `no_evidence`, and rejected outright by
 * JobMatchResponseValidator::assertPossibleCoverageStates(), which has
 * always required `no_evidence` to carry zero citations. V1's
 * `no_evidence` bullet described this as "no *meaningful* evidence" —
 * a qualitative-sufficiency word that invites exactly this
 * misreading, in a field meant to answer a binary existence question.
 * V2 drops "meaningful" for "no evidence at all", and adds the same
 * explicit self-check the `not_assessable` bullet already uses
 * ("if you find yourself wanting to cite... reconsider"), applied to
 * `no_evidence` for the first time. `partial`'s own definition is
 * unchanged — the new sentence only cross-references it, using the
 * same "narrower scope"/"adjacent/transferable" language it already
 * has, plus two concrete examples (personal-vs-professional evidence,
 * partial coverage of a multi-part requirement) it didn't previously
 * spell out. This is a provider-neutral clarification of an invariant
 * JobMatchResponseValidator already enforces deterministically — no
 * validator change, no schema change, no OpenAI-specific behavior
 * change.
 */
final readonly class JobMatchPromptV2
{
    /**
     * Persisted verbatim into JobMatch.prompt_version.
     */
    public function version(): string
    {
        return 'job-match-v2';
    }

    /**
     * Persisted verbatim into JobMatch.schema_version — unchanged from
     * V1, since the structured contract itself did not change.
     */
    public function schemaVersion(): string
    {
        return '1.0';
    }

    public function systemPrompt(): string
    {
        return <<<'PROMPT'
        You are comparing one candidate's verified career history against
        one structured analysis of a job posting, to determine which parts
        of the job are supported by real, already-verified evidence about
        this specific candidate.

        You are NOT evaluating the candidate's suitability, writing a
        resume, drafting a cover letter, or producing any wording meant to
        be shown to an employer. You are answering one narrow question per
        finding: "does the candidate's canonical record support this?" —
        and being precise about HOW WELL and in WHAT WAY, without ever
        overstating what the record actually shows.

        ## What you produce

        Produce one structured JobMatch result: for EVERY finding supplied
        to you, exactly one result covering it — no finding may be skipped,
        and no finding may appear twice. Each finding's result has a
        `coverage` classification, an optional `coverage_rationale`, and
        zero or more `matches` (CareerFact references) and
        `education_matches` (Education references).

        ## Coverage — the aggregate judgment for one finding

        - `supported` — one or more pieces of candidate evidence,
          individually or jointly, substantively demonstrate what this
          finding asks for.
        - `partial` — relevant evidence exists but doesn't fully cover what
          the finding asks for (narrower scope, only adjacent/transferable
          evidence, or — specifically for a finding stating a years-of-
          experience requirement — real uncertainty about whether the
          stated floor is actually met; see the years-of-experience section
          below).
        - `no_evidence` — the supplied candidate dataset contains no
          evidence at all addressing this finding — nothing supplied even
          partially speaks to it. This is a statement about what is IN THE
          DATASET, never a statement about the candidate. Do not use
          `no_evidence` to imply the candidate lacks the capability — only
          that nothing supplied here speaks to it. If you find yourself
          citing any `career_fact_key` or `education_id` for a finding,
          that finding is not `no_evidence` — relevant-but-insufficient
          evidence (narrower in scope, adjacent/transferable, personal
          rather than professional, covering only part of a multi-part
          requirement, or otherwise short of what's asked) is `partial`,
          cited accordingly, never `no_evidence`.
        - `not_assessable` — this finding falls entirely outside what you
          are authorized to determine from career-history and education
          evidence, regardless of how much candidate data existed. Use this
          for findings about:
          - work authorization, visa status, sponsorship, or citizenship
          - the candidate's current physical location or willingness to
            relocate
          - the candidate's current willingness to travel
          - salary/compensation expectations
          - start-date availability
          - any other forward-looking personal preference, eligibility
            state, or willingness the candidate has not stated as a
            verified career fact
          A career history can describe what the candidate has DONE. It
          cannot establish what the candidate CURRENTLY WANTS, PREFERS, OR
          IS ELIGIBLE FOR. Past professional travel is relevant context but
          does NOT prove present willingness to travel. Past remote work
          does NOT prove a current remote-only preference. Never infer a
          preference, willingness, or legal/eligibility status from
          silence — if the dataset is silent on one of these topics, that
          silence is not evidence either way.
          `matches` and `education_matches` must both be empty for
          `not_assessable` — if you find yourself wanting to cite a
          CareerFact for one of these findings, the finding is probably NOT
          `not_assessable` after all; reconsider whether it's actually
          `no_evidence`, `partial`, or `supported` instead.

        ## Relationship — what KIND of support one piece of evidence gives

        Each `matches`/`education_matches` entry has a `relationship`. This
        is categorical — it describes what KIND of connection the evidence
        has to the finding, not how good a match it is on some 1-2-3 scale:

        - `direct` — the evidence demonstrates substantially the same
          capability, technology, domain, scope, or experience the finding
          asks for.
        - `transferable` — the evidence demonstrates a closely analogous
          capability in a different technology, domain, or context —
          legitimately relevant, but not the same experience.
        - `contextual` — the evidence strengthens the candidate's overall
          case or supplies useful background, without independently
          demonstrating the specific capability the finding asks for.

        Prefer `direct` and `transferable` where genuinely warranted — do
        not default everything to `contextual` out of caution, and do not
        inflate everything to `direct` to seem more thorough. Each finding
        can legitimately end up with matches at more than one relationship
        level; that's expected, not a sign of inconsistency.

        ## Do not invent anything

        Every `career_fact_key` you use must be one of the exact keys
        supplied to you. Every `education_id` you use must be one of the
        exact ids supplied to you. Every `job_analysis_finding_id` you use
        must be one of the exact ids supplied to you. Never invent, guess,
        or slightly modify any of these identifiers.

        Never state or imply a candidate accomplishment, technology,
        scope, or outcome that isn't directly present in the supplied
        CareerFact statement or Metric. If a CareerFact's Metric includes a
        `guardrail` field, treat it as a hard constraint on how that figure
        may be characterized — never restate a guarded figure in the way
        its own guardrail explicitly forbids. If a `scope_note` clarifies
        what a figure does or doesn't cover, respect that scope exactly;
        do not generalize a figure beyond the scope its own data states.

        Never state a computed candidate-years figure anywhere, for any
        finding. You may recognize that certain CareerFacts and their
        attached role/date context are relevant to a years-of-experience
        finding, but you must not sum, average, or otherwise calculate a
        number of years and present it as established. If a years-anchored
        finding is only partially supported by the available evidence —
        relevant work exists but its actual duration can't be established
        with confidence from what you were given — reflect that as
        `partial` coverage rather than asserting a number either way.

        ## Preserve contradictions rather than resolving them

        If the candidate's own evidence contains genuinely conflicting
        information relevant to a finding, do not silently pick one side —
        note the tension in the rationale rather than smoothing it over.

        ## Rationale text is internal commentary, not resume wording

        Both `coverage_rationale` and each match's `rationale` are your own
        explanatory notes for a human reviewer — never draft them as
        polished, employer-facing prose, and never treat them as an
        additional source of truth. They exist to make your reasoning
        checkable, not to be copied verbatim into anything shown to anyone
        outside this review.

        ## Structure

        Respond with exactly one result per supplied finding, in any order,
        covering the complete supplied finding set with no omissions and no
        duplicates.
        PROMPT;
    }

    /**
     * @param  array<string, mixed>  $candidatePayload  From CandidatePayloadBuilder.
     * @param  array<string, mixed>  $jobPayload  From JobPayloadBuilder.
     */
    public function userPrompt(array $candidatePayload, array $jobPayload): string
    {
        $candidateJson = json_encode($candidatePayload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $jobJson = json_encode($jobPayload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return "--- Candidate canonical data (verified CareerFacts and Education) ---\n"
            ."{$candidateJson}\n"
            ."--- End of candidate canonical data ---\n\n"
            ."--- Job analysis (role context and every finding to address) ---\n"
            ."{$jobJson}\n"
            .'--- End of job analysis ---';
    }

    /**
     * Identical to JobMatchPromptV1::jsonSchema() — the structured
     * contract itself did not change, only the system prompt's
     * `no_evidence` guidance. See docs/job-match-generation.md.
     *
     * @param  array<int, int>  $validFindingIds
     * @param  array<int, string>  $validFactKeys
     * @param  array<int, int>  $validEducationIds
     * @return array<string, mixed>
     */
    public function jsonSchema(array $validFindingIds, array $validFactKeys, array $validEducationIds): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'findings' => [
                    'type' => 'array',
                    'minItems' => 1,
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'job_analysis_finding_id' => [
                                'type' => 'integer',
                                'enum' => $validFindingIds,
                            ],
                            'coverage' => [
                                'type' => 'string',
                                'enum' => $this->enumValues(JobMatchCoverage::class),
                            ],
                            'coverage_rationale' => ['type' => ['string', 'null']],
                            'matches' => [
                                'type' => 'array',
                                'items' => [
                                    'type' => 'object',
                                    'properties' => [
                                        'career_fact_key' => [
                                            'type' => 'string',
                                            'enum' => $validFactKeys,
                                        ],
                                        'relationship' => [
                                            'type' => 'string',
                                            'enum' => $this->enumValues(MatchRelationship::class),
                                        ],
                                        'rationale' => ['type' => ['string', 'null']],
                                    ],
                                    'required' => ['career_fact_key', 'relationship', 'rationale'],
                                    'additionalProperties' => false,
                                ],
                            ],
                            'education_matches' => [
                                'type' => 'array',
                                'items' => [
                                    'type' => 'object',
                                    'properties' => [
                                        'education_id' => [
                                            'type' => 'integer',
                                            'enum' => $this->nonEmptyEnum($validEducationIds),
                                        ],
                                        'relationship' => [
                                            'type' => 'string',
                                            'enum' => $this->enumValues(MatchRelationship::class),
                                        ],
                                        'rationale' => ['type' => ['string', 'null']],
                                    ],
                                    'required' => ['education_id', 'relationship', 'rationale'],
                                    'additionalProperties' => false,
                                ],
                            ],
                        ],
                        'required' => ['job_analysis_finding_id', 'coverage', 'coverage_rationale', 'matches', 'education_matches'],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            'required' => ['findings'],
            'additionalProperties' => false,
        ];
    }

    /**
     * All JobMatch enums are string-backed by design — the explicit
     * cast reflects that domain constraint, not a workaround.
     *
     * @param  class-string<BackedEnum>  $enum
     * @return array<int, string>
     */
    private function enumValues(string $enum): array
    {
        return array_map(fn (BackedEnum $case): string => (string) $case->value, $enum::cases());
    }

    /**
     * A CareerProfile with zero Education rows would otherwise produce
     * an empty `enum: []` constraint — technically unsatisfiable, and a
     * risky thing to send a structured-output compiler. Substituting a
     * single impossible sentinel id keeps the schema well-formed while
     * still making every real education_id reference structurally
     * rejected; deterministic validation independently rejects the
     * sentinel too, same as any other out-of-set value.
     *
     * @param  array<int, int>  $ids
     * @return array<int, int>
     */
    private function nonEmptyEnum(array $ids): array
    {
        return $ids === [] ? [-1] : $ids;
    }
}
