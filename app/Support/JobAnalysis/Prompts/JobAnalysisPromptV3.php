<?php

namespace App\Support\JobAnalysis\Prompts;

use App\Enums\JobAnalysisEmphasis;
use App\Enums\JobAnalysisFindingBasis;
use App\Enums\JobAnalysisFindingCategory;
use App\Enums\JobAnalysisMaturity;
use App\Enums\JobAnalysisRequirementStrength;
use App\Enums\JobAnalysisSeniority;
use App\Models\JobPosting;
use App\Support\JobAnalysis\JobAnalysisResponseValidator;
use BackedEnum;

/**
 * Revises JobAnalysisPromptV2's evidence policy and adds concision
 * guidance to role_summary/notes — everything else (basis/
 * requirement_strength/emphasis calibration, contradiction
 * preservation, seniority handling, finding categories) is unchanged.
 * `job-analysis-v2` has already been persisted (a real JobAnalysis
 * row exists with that prompt_version), so this is a genuinely new
 * class rather than an in-place edit, per the versioning convention
 * established by JobAnalysisPromptV1/V2 — see
 * docs/job-analysis-generation.md.
 *
 * Motivated by a real TRM Labs Job Analysis generation that produced
 * 11,972 completion tokens without finishing (finish_reason: length)
 * against an 8,192-token budget. A read-only contract audit performed
 * before this revision found the evidence policy was the confirmed,
 * unbounded output-amplification mechanism: V2 explicitly instructed
 * "include each occurrence as its own entry" whenever the posting
 * repeated a requirement, with no maxItems anywhere in the schema —
 * and that evidence is never consumed by any downstream generation
 * stage (JobPayloadBuilder excludes it entirely; TargetTerminologyBuilder
 * never reads it). Evidence's real value — anti-hallucination
 * verification via EvidenceExcerptVerifier, and the human-review page —
 * only ever needed the single strongest supporting excerpt, with a
 * second allowed for genuinely distinct supporting context. This
 * revision caps evidence at
 * JobAnalysisResponseValidator::MAX_EVIDENCE_PER_FINDING (2) — the
 * schema's own maxItems reads that same constant, so the prompt,
 * schema, and validator can never drift apart — and reverses the
 * "one entry per repeated occurrence" instruction. It deliberately
 * does NOT cap findings themselves (recall must be preserved: every
 * materially distinct responsibility, qualification, technology,
 * capability, domain-knowledge item, success measure, culture signal,
 * negative-fit signal, application request, work arrangement, travel
 * item, and authorization item must still be extracted as its own
 * finding), and does NOT change the Ollama model or either stage's
 * token budget.
 *
 * schemaVersion() stays '1.0': this is a cardinality-only schema
 * change (evidence.maxItems added), not a change to the structured
 * contract's shape — the same precedent already established by
 * ResumeSelectionPromptV2 keeping schemaVersion() at '1.3' when
 * MAX_SELECTED_PROJECTS tightened selected_projects.maxItems.
 *
 * Immutable and versioned by class name, exactly like V1 and V2 — see
 * docs/job-analysis-generation.md for the version-bump rules.
 */
final readonly class JobAnalysisPromptV3
{
    /**
     * Persisted verbatim into JobAnalysis.prompt_version.
     */
    public function version(): string
    {
        return 'job-analysis-v3';
    }

    /**
     * Persisted verbatim into JobAnalysis.schema_version — unchanged
     * from V1/V2. See this class's docblock for why a maxItems-only
     * change doesn't warrant a schema_version bump.
     */
    public function schemaVersion(): string
    {
        return '1.0';
    }

    public function systemPrompt(): string
    {
        return <<<'PROMPT'
        You are analyzing a single job posting to determine, as precisely and
        literally as possible, what the EMPLOYER is asking for.

        Your job is strictly limited to describing the posting. You are not
        evaluating, scoring, or comparing any candidate. No candidate, resume,
        or skill set exists in this task — never reference one, and never
        output anything about how well any specific person matches this role.

        ## What you produce

        Produce one structured JobAnalysis object matching the supplied JSON
        schema exactly: a concise role_summary (2-4 sentences covering the
        role's core purpose, team/product context, and seniority signal —
        not a restatement of every responsibility, which belongs in findings
        instead), an overall_seniority judgment with a short
        seniority_rationale, and a list of findings extracted from the
        posting text.

        ## Findings

        A finding is one discrete observation about what the posting says or
        clearly implies — never a paragraph summary, never a bag of keywords.
        Keep distinct named technologies, tools, and requirements as separate
        findings rather than flattening them into one vague finding. Each
        finding has:

        - category — exactly one of: responsibility, required_qualification,
          preferred_qualification, technology, capability, domain_knowledge,
          success_measure, culture_signal, negative_fit_signal,
          application_request, work_arrangement, travel, authorization. Use
          these exact lowercase strings; never invent a new category.
        - statement — a concise, normalized restatement of the finding in your
          own words. This is NOT a quote — keep it short and readable. Verbatim
          text belongs only in evidence (see below).
        - label — an optional short internal tag for the finding (e.g.
          "backend_experience_floor"), or null.
        - basis — how much interpretation the finding's SEMANTIC PROPOSITION
          required, not merely whether evidence exists for it. Read this
          carefully: a finding can have perfectly verbatim evidence and still
          not be explicit, if reaching the finding's actual conclusion took
          more than restating what the text plainly says.
          - explicit — the source directly states the substance of the
            normalized finding. Reasonable rewording/normalization is fine,
            but the proposition itself does not require interpretation beyond
            what is directly stated.
            Example: posting says "5+ years of backend experience required."
            Finding: "5+ years of backend experience required." -> explicit —
            the finding IS what the text says, just restated concisely.
          - strongly_implied — the source does not directly state the
            normalized proposition, but its wording/context strongly supports
            it: deriving the finding required combining nearby statements,
            reading clear intent, or naming an unstated-but-strongly-supported
            expectation.
            Example: posting says "We're not looking to teach you the latest
            capabilities; we're looking to be wowed, and to learn from you."
            Finding: "Candidates are expected to already be near the frontier
            of AI-tooling knowledge, not learning it on the job." ->
            strongly_implied — the sentence is literally about not teaching
            and wanting to learn from the candidate; the finding's actual
            claim (an expected existing skill level) is a natural but
            unstated reading of that.
          - inferred — the finding required a further defensible inference
            beyond direct statement or strong implication. Use sparingly, and
            never for a speculative assumption you can't tie back to real
            textual signal.
            Example: posting stresses "ship a rough v1 today, improve it
            Monday" and lists "want detailed direction before you start" as a
            poor-fit signal. Finding: "Engineers who need close oversight or
            prefer slower, more deliberate release cycles are likely a poor
            fit." -> inferred — this combines several separate signals into a
            broader conclusion no single sentence states.
        - requirement_strength — whether THIS SPECIFIC finding is itself a
          candidate-selection requirement — not merely whether it was found
          somewhere inside a "Requirements" section. One of required,
          preferred, not_required, or null:
          - required — the posting directly states this specific finding as a
            mandatory qualification or condition.
          - preferred — the posting directly states this specific finding as
            desirable/bonus/preferred, not mandatory.
          - not_required — the posting directly states that this specific
            knowledge/experience is unnecessary (e.g. "ERP experience is not
            required", "no prior experience necessary"). This is a real,
            positive signal — always use it rather than omitting the finding
            or leaving this field null.
          - null — responsibilities, role context, success expectations,
            descriptive examples, sub-details, and technologies mentioned
            without their own demonstrated hiring-bar status. Also use null
            whenever the category never carries a requirement strength (e.g.
            culture_signal, success_measure), or the posting is genuinely
            silent about this specific finding's strength. Never guess
            required or preferred when the posting is silent about THIS
            finding — leave it null instead.
          A structural heading (e.g. appearing under "Requirements" or
          "Minimum Requirements") is real evidence and should be weighed, but
          requirement_strength must still be judged at the semantic
          granularity of the individual finding — never propagate a parent
          item's requirement_strength automatically onto every independently
          extracted child/detail/example finding beneath it.
          Example: a posting lists, under "Minimum Requirements," a bullet
          naming a broad capability area, followed by an unordered set of
          specific sub-techniques or examples elaborating it, none of which
          the posting separately states as its own mandatory item. The
          top-level capability finding may legitimately be required (it is
          the thing the posting actually names as a requirement). Each
          sub-technique, extracted as its own finding, should default to null
          unless the posting independently and specifically states that exact
          sub-technique is mandatory — being elaboration of a required item is
          not the same as being independently required itself.
        - emphasis — high, normal, or low. This is about how much a finding
          stands out relative to the REST OF THIS POSTING, not about how
          important the finding seems in general — importance alone never
          justifies high.
          - normal — the default for ordinary stated requirements,
            responsibilities, technologies, and qualifications. Most findings
            should be normal.
          - high — reserve for findings with real evidence of being elevated
            above the rest of the posting: unusually stressed language,
            repetition in multiple places, central role identity, explicit
            statements of priority, primary-workflow status (vs. incidental
            mention), or a major success criterion. If you can't point to a
            specific reason a finding stands out MORE than the posting's other
            findings, it is normal, not high.
          - low — use only when the source genuinely deemphasizes or
            marginalizes the point (e.g. framed as a minor nice-to-have among
            otherwise-strong requirements).
          Keep high selective — if most findings end up high, the field has
          stopped being useful.
        - maturity — production, prototype_or_experimental, or unspecified.
          Mainly relevant to technology/capability findings: distinguish real
          production use from experimentation/prototyping when the posting
          draws that distinction (it often does, explicitly — e.g. "production
          features," not "occasional experimentation"). Use unspecified when
          the posting gives no signal either way, or maturity doesn't apply to
          this finding.
        - years_experience_min / years_experience_max — only when the posting
          states an experience requirement in years. Transcribe faithfully,
          never compute or invent:
          - "5+ years" -> years_experience_min: 5, years_experience_max: null
            (a floor, not a range — do not invent a ceiling).
          - "7-10 years" -> years_experience_min: 7, years_experience_max: 10.
          - No stated number -> both null.
        - recency_requirement — free text if the posting requires the
          experience to be recent (e.g. "within the last six months"), else
          null.
        - time_horizon — free text if the posting frames a finding around a
          time window (e.g. "in your first 90 days"), else null.
        - notes — free text for anything worth flagging that doesn't fit the
          other fields, or null. Keep it to one short sentence when used — a
          brief flag (e.g. "phrased as a stretch goal, not a hard
          requirement"), not a second statement of the finding or a restated
          rationale.
        - evidence — 1-2 verbatim excerpts from the posting supporting this
          finding (never more than 2). Read the next section carefully for
          how to choose them.

        ## Evidence — this is the most important part

        Every excerpt you return will be checked programmatically against the
        exact job posting text you were given. An excerpt must be a
        character-for-character substring of that text (only whitespace runs
        are collapsed before comparison — nothing else is normalized). If even
        ONE excerpt anywhere in your response cannot be verified this way, the
        ENTIRE analysis is rejected and nothing is saved. This is strict, with
        no exceptions.

        Each finding takes at most 2 evidence entries — never more; the
        schema itself rejects a 3rd. Choose the single clearest, strongest
        excerpt that supports the finding first. Add a second excerpt only
        when it contributes materially different supporting context (for
        example, one excerpt states a specific number and a separate excerpt
        states a distinct qualifying condition). A second excerpt that only
        restates or lightly rewords the first adds nothing — leave it out.

        Therefore:
        - Copy excerpts exactly as they appear in the posting. Do not
          paraphrase, summarize, fix typos, or "clean up" quotation marks,
          dashes, or spacing beyond what naturally results from copying text.
        - Never fabricate a quote to support a finding you believe is true but
          that the text doesn't actually contain. If you cannot find real
          supporting text, do not include the finding at all.
        - When the same requirement is stated more than once in the posting
          (in different sections, in different words), do NOT add a separate
          evidence entry for every occurrence. Pick the single occurrence that
          states it most clearly; add a second only if it genuinely adds new
          supporting context beyond repetition. Never create duplicate
          findings for the same requirement, and never add an evidence entry
          purely because the posting happens to repeat itself.

        ## Preserve contradictions

        If the posting contains genuinely conflicting statements (for example,
        it describes the role as fully remote in one place and lists specific
        required office locations in another), do not resolve, average, or
        silently pick one. Represent both as findings with their own evidence
        — the contradiction itself is real signal for a human to review, not
        something to smooth over.

        ## Do not invent requirements

        Only extract what the text actually supports (directly, strongly
        implied, or reasonably inferred). Do not add findings based on what a
        job with this title "usually" requires. Do not add numeric confidence
        scores anywhere — they are not part of this schema and are not
        wanted.

        ## Seniority

        overall_seniority is a single synthesized judgment about the whole
        posting, not a citation of one passage — always inferred by nature. It
        is always one of junior, mid, senior, staff_or_above, or unspecified
        (use unspecified when the posting gives no real signal — never leave
        this null). seniority_rationale is a short, 1-3 sentence explanation
        of your reasoning.

        ## What you are not doing

        You are not scoring, ranking, or evaluating any candidate. You have no
        candidate information and none should be assumed. You are not writing
        a resume, cover letter, or any tailored content. You are only
        describing, as literally and completely as the text supports, what
        this specific employer is asking for.
        PROMPT;
    }

    public function userPrompt(JobPosting $posting): string
    {
        $location = $posting->location ?? 'Not specified';

        return "Company: {$posting->company}\n"
            ."Title: {$posting->title}\n"
            ."Location: {$location}\n\n"
            ."--- Job posting description (verbatim) ---\n"
            ."{$posting->description}\n"
            .'--- End of job posting description ---';
    }

    /**
     * Identical to JobAnalysisPromptV2::jsonSchema() except
     * evidence.maxItems, which reads
     * JobAnalysisResponseValidator::MAX_EVIDENCE_PER_FINDING rather than
     * an independently-maintained number, so the prompt schema and the
     * validator's own `max:` rule can never drift apart. See this
     * class's docblock for the evidence-cap rationale.
     *
     * @return array<string, mixed>
     */
    public function jsonSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'role_summary' => ['type' => 'string'],
                'overall_seniority' => [
                    'type' => 'string',
                    'enum' => $this->enumValues(JobAnalysisSeniority::class),
                ],
                'seniority_rationale' => ['type' => 'string'],
                'findings' => [
                    'type' => 'array',
                    'minItems' => 1,
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'category' => [
                                'type' => 'string',
                                'enum' => $this->enumValues(JobAnalysisFindingCategory::class),
                            ],
                            'statement' => ['type' => 'string'],
                            'label' => ['type' => ['string', 'null']],
                            'basis' => [
                                'type' => 'string',
                                'enum' => $this->enumValues(JobAnalysisFindingBasis::class),
                            ],
                            'requirement_strength' => [
                                'type' => ['string', 'null'],
                                'enum' => [...$this->enumValues(JobAnalysisRequirementStrength::class), null],
                            ],
                            'emphasis' => [
                                'type' => 'string',
                                'enum' => $this->enumValues(JobAnalysisEmphasis::class),
                            ],
                            'maturity' => [
                                'type' => 'string',
                                'enum' => $this->enumValues(JobAnalysisMaturity::class),
                            ],
                            'years_experience_min' => ['type' => ['number', 'null']],
                            'years_experience_max' => ['type' => ['number', 'null']],
                            'recency_requirement' => ['type' => ['string', 'null']],
                            'time_horizon' => ['type' => ['string', 'null']],
                            'notes' => ['type' => ['string', 'null']],
                            'evidence' => [
                                'type' => 'array',
                                'minItems' => 1,
                                'maxItems' => JobAnalysisResponseValidator::MAX_EVIDENCE_PER_FINDING,
                                'items' => [
                                    'type' => 'object',
                                    'properties' => [
                                        'excerpt' => ['type' => 'string'],
                                        'source_section' => ['type' => ['string', 'null']],
                                        'source_locator' => ['type' => ['string', 'null']],
                                    ],
                                    'required' => ['excerpt', 'source_section', 'source_locator'],
                                    'additionalProperties' => false,
                                ],
                            ],
                        ],
                        'required' => [
                            'category', 'statement', 'label', 'basis', 'requirement_strength',
                            'emphasis', 'maturity', 'years_experience_min', 'years_experience_max',
                            'recency_requirement', 'time_horizon', 'notes', 'evidence',
                        ],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            'required' => ['role_summary', 'overall_seniority', 'seniority_rationale', 'findings'],
            'additionalProperties' => false,
        ];
    }

    /**
     * All six JobAnalysis enums are string-backed by design — the
     * explicit cast reflects that domain constraint, not a workaround.
     *
     * @param  class-string<BackedEnum>  $enum
     * @return array<int, string>
     */
    private function enumValues(string $enum): array
    {
        return array_map(fn (BackedEnum $case): string => (string) $case->value, $enum::cases());
    }
}
