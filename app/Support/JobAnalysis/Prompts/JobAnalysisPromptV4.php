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
 * Replaces JobAnalysisPromptV3's evidence contract with deterministic
 * source-segment references — everything else (basis/requirement_strength/
 * emphasis calibration, contradiction preservation, seniority handling,
 * finding categories, role_summary/notes concision) is unchanged from V3.
 * `job-analysis-v3` has already been persisted (a real JobAnalysis row
 * exists with that prompt_version), so this is a genuinely new class
 * rather than an in-place edit, per the versioning convention
 * established by JobAnalysisPromptV1/V2/V3 — see
 * docs/job-analysis-generation.md.
 *
 * Motivated by three real TRM Labs generations that each reached
 * EvidenceExcerptVerifier (no truncation, clean decode, clean schema
 * validation) and were then discarded entirely because one
 * model-retyped "verbatim" evidence excerpt differed from the source by
 * a handful of characters — a curly apostrophe folded to ASCII in one
 * run, whitespace inserted mid-word in another. Both were confirmed,
 * via a full character-level diff against the real stored source, to
 * be substantively verbatim; the retyping mechanism itself, not the
 * model's judgment, was the actual point of failure. A read-only
 * architecture investigation concluded the fix that structurally
 * eliminates this whole failure class — rather than reactively adding
 * another narrow normalization rule for the next unanticipated
 * transformation — is to stop asking the model to reproduce source text
 * at all.
 *
 * V4's evidence contract: App\Support\JobAnalysis\SegmentJobPostingDescription
 * deterministically splits the posting description into small,
 * stably-ID'd segments ("S001", "S002", ...) before generation. The
 * model is shown those labeled segments and returns evidence_refs (1-2
 * segment ids per finding, same cardinality as V3's evidence array —
 * still bounded by JobAnalysisResponseValidator::MAX_EVIDENCE_PER_FINDING,
 * so the prompt, schema, and validator can never drift apart) instead of
 * retyping the segment's text. GenerateJobAnalysis resolves each
 * validated ref back to the segment's exact, untouched original text
 * before persisting — the persisted evidence is therefore always
 * character-for-character identical to the source by construction, not
 * merely verified to be so after the fact. EvidenceExcerptVerifier has
 * no remaining role under V4 and has been removed (see
 * docs/job-analysis-generation.md).
 *
 * schemaVersion() bumps to '2.0': unlike V2->V3's evidence.maxItems-only
 * change (which correctly kept schemaVersion at '1.0' — see V3's own
 * docblock), this is a genuine structural change to the contract
 * (evidence: array<{excerpt, source_section, source_locator}> removed;
 * evidence_refs: array<string> added), matching
 * docs/job-analysis-contract.md's stated rule to bump schema_version
 * when a field is added/removed/renamed.
 *
 * Does NOT cap findings themselves (recall must be preserved — see V3's
 * own docblock for the full list of category types), does NOT change
 * the Ollama model, and does NOT change either stage's token budget.
 *
 * Immutable and versioned by class name, exactly like V1/V2/V3 — see
 * docs/job-analysis-generation.md for the version-bump rules.
 */
final readonly class JobAnalysisPromptV4
{
    /**
     * Persisted verbatim into JobAnalysis.prompt_version.
     */
    public function version(): string
    {
        return 'job-analysis-v4';
    }

    /**
     * Persisted verbatim into JobAnalysis.schema_version. See this
     * class's docblock for why this is a real bump from V1/V2/V3's
     * '1.0', not a cardinality-only change.
     */
    public function schemaVersion(): string
    {
        return '2.0';
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
          own words. This is NOT a quote — keep it short and readable.
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
        - evidence_refs — 1-2 segment ids (never more) citing the source
          segments that support this finding. Read the next section carefully
          for how to choose them.

        ## Evidence — this is the most important part

        The job posting description below is presented as a numbered list of
        source segments, each labeled with an id like [S001]. You do not
        transcribe or quote any text yourself — you only cite which segment
        id(s) support each finding, exactly as given. An id that was not
        shown to you does not exist and must never be cited; the schema
        itself constrains which ids are valid, but treat this as an absolute
        rule regardless: never invent, guess, or slightly alter a segment id.

        Each finding takes at most 2 evidence_refs — never more; the schema
        itself rejects a 3rd. Choose the single segment that most clearly and
        directly supports the finding first. Add a second only when it
        contributes materially different supporting context (for example,
        one segment states a specific number and a separate segment states a
        distinct qualifying condition). Do not add a second ref merely
        because the posting repeats the same requirement in another segment
        — pick whichever single occurrence states it most clearly, and only
        add a second for genuinely different supporting context, not
        repetition. Never create duplicate findings for the same requirement.

        If you cannot find a real supporting segment for a finding, do not
        include that finding at all.

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

    /**
     * @param  array<string, string>  $segments  Ordered segment id => exact source text, from SegmentJobPostingDescription.
     */
    public function userPrompt(JobPosting $posting, array $segments): string
    {
        $location = $posting->location ?? 'Not specified';

        $labeledSegments = '';
        foreach ($segments as $id => $text) {
            $labeledSegments .= "[{$id}] {$text}\n";
        }

        return "Company: {$posting->company}\n"
            ."Title: {$posting->title}\n"
            ."Location: {$location}\n\n"
            ."--- Job posting description (source segments) ---\n"
            .$labeledSegments
            .'--- End of job posting description ---';
    }

    /**
     * @param  array<int, string>  $validSegmentIds  Every segment id actually supplied in this call's userPrompt() — from the same SegmentJobPostingDescription call, so they always agree.
     * @return array<string, mixed>
     */
    public function jsonSchema(array $validSegmentIds): array
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
                            // Schema-level enum is advisory/provider-level
                            // protection only — how strictly a given
                            // provider's structured-output decoding
                            // actually enforces it isn't something to
                            // rely on. JobAnalysisResponseValidator's own
                            // Rule::in() check against this exact same
                            // id set is what's authoritative. See this
                            // class's docblock.
                            'evidence_refs' => [
                                'type' => 'array',
                                'minItems' => 1,
                                'maxItems' => JobAnalysisResponseValidator::MAX_EVIDENCE_PER_FINDING,
                                'items' => [
                                    'type' => 'string',
                                    'enum' => $validSegmentIds,
                                ],
                            ],
                        ],
                        'required' => [
                            'category', 'statement', 'label', 'basis', 'requirement_strength',
                            'emphasis', 'maturity', 'years_experience_min', 'years_experience_max',
                            'recency_requirement', 'time_horizon', 'notes', 'evidence_refs',
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
