<?php

namespace App\Support\JobMatch\Prompts;

use App\Enums\JobMatchCoverage;
use App\Enums\MatchRelationship;
use BackedEnum;

/**
 * Builds on JobMatchPromptV2 unchanged — V2's `no_evidence`/`partial`
 * clarification is preserved exactly, word for word. V3 adds two new
 * things V2 never addressed: what an attached CareerFact `skill`
 * actually establishes (and doesn't), and a tightened, more mechanical
 * `direct`/`transferable`/`contextual` definition anchored to the
 * finding's literal statement rather than its broader category.
 * Immutable and versioned by class name, exactly like V1 and V2 — see
 * docs/job-match-generation.md for the version-bump rules.
 *
 * Motivated by two concrete defects found in live qwen3.8:27b
 * evaluations of V2 (see the local-Ollama-provider investigation),
 * neither of which V2's contract addressed:
 *
 * 1. Evidentiary-faithfulness: a citation's rationale described a
 *    technology (e.g. a general-purpose language) as demonstrated by a
 *    CareerFact whose own statement and attached Skills never
 *    established it — the detail was real, but belonged to a
 *    *different* CareerFact for the same employer. Nothing in V2 told
 *    the model that an attached Skill is legitimate evidence for the
 *    fact it's attached to specifically, nor that a citation may only
 *    draw on that one fact's own fields.
 * 2. Relationship calibration: the identical CareerFact, cited for two
 *    near-identical findings, was labeled `direct` for one and
 *    `contextual` for the other — and separately, a citation's own
 *    rationale sometimes argued for `transferable` while the
 *    structured `relationship` field said `direct` anyway. V2's
 *    relationship definitions anchored to "what the finding asks for"
 *    without specifying whether that means the finding's literal named
 *    technology or its broader capability category, leaving room for
 *    exactly this drift.
 *
 * Both are providers-neutral prompt-contract gaps, not something
 * JobMatchResponseValidator is designed to catch (it validates
 * structure/referential-integrity, never rationale content) and not
 * something this revision asks it to catch — no validator change, no
 * schema change, no candidate-payload change.
 */
final readonly class JobMatchPromptV3
{
    /**
     * Persisted verbatim into JobMatch.prompt_version.
     */
    public function version(): string
    {
        return 'job-match-v3';
    }

    /**
     * Persisted verbatim into JobMatch.schema_version — unchanged from
     * V1/V2, since the structured contract itself did not change.
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
        has to the finding, not how good a match it is on some 1-2-3 scale.
        Anchor this to the finding's own literal statement, not its broader
        category or label:

        - `direct` — the CareerFact itself demonstrates substantially the
          same specific capability, technology, domain, scope, or
          experience the finding's statement literally names. If a finding
          names a specific technology, evidence of a different technology
          is not `direct` merely because both belong to the same broad
          category.
        - `transferable` — the CareerFact demonstrates a closely analogous
          capability, but in a different technology, domain, or context —
          legitimately relevant, but not the same experience. For example,
          operating an equivalent CI/CD practice on a different CI system
          may be `transferable` to a finding naming GitHub Actions
          specifically.
        - `contextual` — the CareerFact strengthens the candidate's overall
          case or supplies nearby, relevant background, without itself
          demonstrating the requested capability or a mechanically
          analogous substitute for it. For example, using GitLab for
          source control and code review is relevant context for a finding
          asking for CI/CD, GitHub Actions, and Terraform — but
          source-control usage alone does not demonstrate CI/CD, and
          should not become `direct` merely because both are
          developer-infrastructure tooling.

        Prefer `direct` and `transferable` where genuinely warranted — do
        not default everything to `contextual` out of caution, and do not
        inflate everything to `direct` to seem more thorough. Each finding
        can legitimately end up with matches at more than one relationship
        level; that's expected, not a sign of inconsistency. A finding's
        overall `coverage` is a holistic judgment across all its matches,
        independent of any single match's `relationship` — `supported`
        coverage can still include `transferable` or `contextual` matches
        alongside direct ones, and a `partial` finding can have direct
        evidence for one part of a multi-part requirement while lacking
        another part entirely.

        The `relationship` value and its `rationale` must agree. If a
        match's own rationale describes the evidence as analogous,
        transferable, a different technology/domain/context, adjacent
        rather than equivalent, or relevant-but-not-direct, do not emit
        `relationship: direct` for it — unless that same rationale
        separately identifies the specific part of the CareerFact that
        does directly satisfy the finding.

        ## Skills, and each CareerFact's evidence boundary

        A CareerFact's attached `skills` are legitimate evidence that a
        technology, platform, capability, or practice is associated with
        that specific CareerFact, even when the fact's own `statement`
        text doesn't name it — treat an attached Skill as real evidence,
        not decoration. An attached Skill alone does NOT establish
        anything stronger than that association: not depth of expertise,
        not years or duration of use, not production scale, not personal
        implementation or ownership, not that it was the candidate's
        primary technology, not extensive hands-on coding in it, not
        architecture ownership, and not any other detail the fact's own
        `statement`/`metric`/`guardrail`/`scope_note` doesn't itself
        state. If a finding asks for something stronger than mere
        association — depth, scale, ownership, primary-language status —
        a Skill by itself does not satisfy that; look to what the fact's
        own statement and metric actually say.

        Each CareerFact is its own evidence boundary. When citing a
        CareerFact, use only that fact's own statement, attached Skills,
        metric/guardrail/scope_note, and attribution/role-date context.
        Never import a technology, metric, implementation detail,
        ownership claim, or other detail from a different CareerFact into
        this citation's rationale — even when both facts share the same
        employer, the same role, or closely related projects, and even
        when the imported detail is true of that other fact. If a detail
        from another CareerFact is genuinely relevant to the finding, cite
        that other CareerFact separately instead.

        ## Do not invent anything

        Every `career_fact_key` you use must be one of the exact keys
        supplied to you. Every `education_id` you use must be one of the
        exact ids supplied to you. Every `job_analysis_finding_id` you use
        must be one of the exact ids supplied to you. Never invent, guess,
        or slightly modify any of these identifiers.

        Never state or imply a candidate accomplishment, technology,
        scope, or outcome that isn't directly present in that CareerFact's
        own statement, Metric, or attached Skills. If a CareerFact's
        Metric includes a `guardrail` field, treat it as a hard constraint
        on how that figure may be characterized — never restate a
        guarded figure in the way its own guardrail explicitly forbids.
        If a `scope_note` clarifies what a figure does or doesn't cover,
        respect that scope exactly; do not generalize a figure beyond the
        scope its own data states.

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
     * Identical to JobMatchPromptV2::jsonSchema() (and V1's) — the
     * structured contract itself did not change, only the system
     * prompt's Skill-authority, fact-local-evidence, and relationship
     * guidance. See docs/job-match-generation.md.
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
