<?php

namespace App\Support\JobAnalysis\Prompts;

use App\Enums\JobAnalysisEmphasis;
use App\Enums\JobAnalysisFindingBasis;
use App\Enums\JobAnalysisFindingCategory;
use App\Enums\JobAnalysisMaturity;
use App\Enums\JobAnalysisRequirementStrength;
use App\Enums\JobAnalysisSeniority;
use App\Models\JobPosting;
use BackedEnum;

/**
 * The first production JobAnalysis prompt: system instructions, the
 * per-job input payload, and the structured-output schema those
 * instructions describe. Immutable and versioned by class name — a
 * wording revision becomes JobAnalysisPromptV2 rather than editing this
 * class in place, so `prompt_version` on an already-persisted
 * JobAnalysis always resolves to a knowable, inspectable prompt. See
 * docs/job-analysis-generation.md for the version-bump rules.
 */
final readonly class JobAnalysisPromptV1
{
    /**
     * Persisted verbatim into JobAnalysis.prompt_version.
     */
    public function version(): string
    {
        return 'job-analysis-v1';
    }

    /**
     * Persisted verbatim into JobAnalysis.schema_version — matches
     * docs/job-analysis-contract.md's "1.0".
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
        schema exactly: a role_summary, an overall_seniority judgment with a
        short seniority_rationale, and a list of findings extracted from the
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
        - basis — how directly the source text supports this finding:
          - explicit — the posting states it directly and unambiguously.
          - strongly_implied — the posting doesn't say it outright but leaves
            little real doubt.
          - inferred — a reasonable reading of the text, not a direct
            statement.
        - requirement_strength — one of required, preferred, not_required, or
          null.
          - not_required means the posting EXPLICITLY disclaims the
            qualification (e.g. "ERP experience is not required", "no prior
            experience necessary"). This is a real, positive signal — always
            use it when the posting says something is not required, rather
            than omitting the finding or leaving this field null.
          - null means either this category never carries a requirement
            strength (e.g. culture_signal, success_measure), or the posting
            simply never states a strength for an otherwise-relevant finding.
            Never guess required or preferred when the posting is genuinely
            silent — leave it null instead.
        - emphasis — how central/repeated the finding is in the posting: high,
          normal, or low. Independent of requirement_strength — a requirement
          can be required and low-emphasis (mentioned once, in passing), or
          preferred and high-emphasis (repeatedly stressed despite being
          technically optional). Use normal as the default when nothing makes
          a finding stand out either way.
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
          other fields (e.g. "stated twice, in different sections"), or null.
        - evidence — a non-empty list of verbatim excerpts from the posting
          supporting this finding. Read the next section carefully.

        ## Evidence — this is the most important part

        Every excerpt you return will be checked programmatically against the
        exact job posting text you were given. An excerpt must be a
        character-for-character substring of that text (only whitespace runs
        are collapsed before comparison — nothing else is normalized). If even
        ONE excerpt anywhere in your response cannot be verified this way, the
        ENTIRE analysis is rejected and nothing is saved. This is strict, with
        no exceptions.

        Therefore:
        - Copy excerpts exactly as they appear in the posting. Do not
          paraphrase, summarize, fix typos, or "clean up" quotation marks,
          dashes, or spacing beyond what naturally results from copying text.
        - Never fabricate a quote to support a finding you believe is true but
          that the text doesn't actually contain. If you cannot find real
          supporting text, do not include the finding at all.
        - When the same requirement is stated more than once in the posting
          (in different sections, in different words), include each
          occurrence as its own entry in that finding's evidence list — do not
          collapse repeated evidence into one entry, and do not create
          duplicate findings for the same requirement.

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
     * The JSON Schema passed to OpenAI's text.format (json_schema,
     * strict: true). Enum value lists are derived from the actual
     * backed enums, never duplicated as string literals, so this schema
     * can never silently drift from App\Enums\JobAnalysis*.
     *
     * Every property is required (present as a key) even when its value
     * may be null — strict structured-output modes generally treat a
     * nullable-but-omitted property as invalid unless it's explicitly
     * listed in `required`, and we want a consistently shaped response
     * for the validator regardless. A few
     * enum fields (category, basis, emphasis, maturity,
     * overall_seniority) are additionally never null in this contract:
     * each either has no legitimate "not applicable" reading (category,
     * basis, emphasis) or already carries an explicit "no signal" case
     * of its own (maturity's `unspecified`, overall_seniority's
     * `unspecified`) — see docs/job-analysis-generation.md.
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
