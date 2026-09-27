<?php

namespace App\Support\JobAnalysis;

use App\Contracts\GeneratesJobAnalysis;
use App\Enums\JobAnalysisEmphasis;
use App\Enums\JobAnalysisFindingBasis;
use App\Enums\JobAnalysisFindingCategory;
use App\Enums\JobAnalysisMaturity;
use App\Enums\JobAnalysisRequirementStrength;
use App\Enums\JobAnalysisSeniority;
use App\Exceptions\InvalidJobAnalysisResponseException;
use App\Exceptions\JobAnalysisProviderException;
use App\Models\JobAnalysis;
use App\Models\JobPosting;
use App\Support\JobAnalysis\Prompts\JobAnalysisPromptV4;
use Illuminate\Support\Facades\DB;

/**
 * The whole generation pipeline for one JobPosting, start to finish:
 *
 *   deterministic source segmentation -> prompt -> provider -> validate
 *   (including every evidence_refs value against the exact supplied
 *   segment set) -> resolve refs to their exact source text -> trusted
 *   draft -> one atomic transaction creating JobAnalysis + Findings +
 *   Evidence
 *
 * Everything before the transaction can fail loudly without touching
 * the database at all. Nothing about the JobPosting except its
 * company/title/location/description ever leaves this class — no
 * CareerFact, Skill, Project, Employer, or Role is referenced anywhere
 * in this pipeline. See docs/job-analysis-generation.md.
 *
 * As of V4, the model never reproduces evidence text — it cites segment
 * ids from SegmentJobPostingDescription's deterministic split of
 * $posting->description, and this class resolves each validated id
 * back to that segment's exact, untouched original text before
 * persisting. There is therefore no generated excerpt left to verify
 * for copy fidelity — EvidenceExcerptVerifier is not part of this
 * pipeline and has been removed. See this file's own toEvidenceDraft().
 */
final class GenerateJobAnalysis
{
    public function __construct(
        private readonly GeneratesJobAnalysis $provider,
        private readonly JobAnalysisPromptV4 $prompt,
        private readonly JobAnalysisResponseValidator $validator,
        private readonly SegmentJobPostingDescription $segmenter,
    ) {}

    /**
     * Generates and persists a new, immutable JobAnalysis snapshot for
     * the given JobPosting. Always creates a new snapshot — never
     * updates a prior one, even if one already exists for this posting.
     *
     * @throws JobAnalysisProviderException on provider/transport failure
     * @throws InvalidJobAnalysisResponseException on validation failure (including an invented/nonexistent evidence_refs id)
     */
    public function generate(JobPosting $posting): JobAnalysis
    {
        $segments = $this->segmenter->segment($posting->description);

        $providerResponse = $this->provider->generate(
            $this->prompt->systemPrompt(),
            $this->prompt->userPrompt($posting, $segments),
            $this->prompt->jsonSchema(array_keys($segments)),
        );

        $validated = $this->validator->validate($providerResponse->structuredContent, array_keys($segments));

        $draft = $this->toDraft($validated, $segments);

        return DB::transaction(function () use ($posting, $draft, $providerResponse, $validated) {
            $analysis = $posting->jobAnalyses()->create([
                'schema_version' => $this->prompt->schemaVersion(),
                'prompt_version' => $this->prompt->version(),
                'generated_by' => $providerResponse->generatedBy(),
                'generated_at' => now(),
                'raw_response' => $validated,
                'role_summary' => $draft->roleSummary,
                'overall_seniority' => $draft->overallSeniority,
                'seniority_rationale' => $draft->seniorityRationale,
            ]);

            foreach ($draft->findings as $findingDraft) {
                $finding = $analysis->findings()->create([
                    'category' => $findingDraft->category,
                    'statement' => $findingDraft->statement,
                    'label' => $findingDraft->label,
                    'basis' => $findingDraft->basis,
                    'requirement_strength' => $findingDraft->requirementStrength,
                    'emphasis' => $findingDraft->emphasis,
                    'maturity' => $findingDraft->maturity,
                    'years_experience_min' => $findingDraft->yearsExperienceMin,
                    'years_experience_max' => $findingDraft->yearsExperienceMax,
                    'recency_requirement' => $findingDraft->recencyRequirement,
                    'time_horizon' => $findingDraft->timeHorizon,
                    'notes' => $findingDraft->notes,
                ]);

                foreach ($findingDraft->evidence as $evidenceDraft) {
                    $finding->evidence()->create([
                        'excerpt' => $evidenceDraft->excerpt,
                        'source_section' => $evidenceDraft->sourceSection,
                        'source_locator' => $evidenceDraft->sourceLocator,
                    ]);
                }
            }

            return $analysis;
        });
    }

    /**
     * @param  array<string, mixed>  $validated
     * @param  array<string, string>  $segments  Segment id => exact source text.
     */
    private function toDraft(array $validated, array $segments): JobAnalysisDraft
    {
        /** @var array<int, array<string, mixed>> $findings */
        $findings = $validated['findings'];

        return new JobAnalysisDraft(
            roleSummary: $validated['role_summary'],
            overallSeniority: JobAnalysisSeniority::from($validated['overall_seniority']),
            seniorityRationale: $validated['seniority_rationale'],
            findings: array_map(fn (array $finding) => $this->toFindingDraft($finding, $segments), $findings),
        );
    }

    /**
     * @param  array<string, mixed>  $finding
     * @param  array<string, string>  $segments
     */
    private function toFindingDraft(array $finding, array $segments): JobAnalysisFindingDraft
    {
        /** @var array<int, string> $evidenceRefs */
        $evidenceRefs = $finding['evidence_refs'];

        return new JobAnalysisFindingDraft(
            category: JobAnalysisFindingCategory::from($finding['category']),
            statement: $finding['statement'],
            label: $finding['label'] ?? null,
            basis: JobAnalysisFindingBasis::from($finding['basis']),
            requirementStrength: isset($finding['requirement_strength'])
                ? JobAnalysisRequirementStrength::from($finding['requirement_strength'])
                : null,
            emphasis: JobAnalysisEmphasis::from($finding['emphasis']),
            maturity: JobAnalysisMaturity::from($finding['maturity']),
            yearsExperienceMin: isset($finding['years_experience_min']) ? (float) $finding['years_experience_min'] : null,
            yearsExperienceMax: isset($finding['years_experience_max']) ? (float) $finding['years_experience_max'] : null,
            recencyRequirement: $finding['recency_requirement'] ?? null,
            timeHorizon: $finding['time_horizon'] ?? null,
            notes: $finding['notes'] ?? null,
            evidence: array_map(fn (string $ref) => $this->toEvidenceDraft($ref, $segments), $evidenceRefs),
        );
    }

    /**
     * Resolves an already-validated segment id to its exact, untouched
     * source text — this is the one place a segment id ever becomes
     * persisted evidence. $segments[$ref] is guaranteed to exist:
     * JobAnalysisResponseValidator has already rejected the entire
     * response if any evidence_refs value fell outside this exact
     * segment set.
     *
     * source_locator carries the segment id itself — genuinely
     * meaningful now, unlike the free-text, never-instructed field V3
     * left unused. source_section has no V4 equivalent (there was never
     * real prompt guidance for what to put there even under V3) and is
     * left null rather than inventing a value to fill the column.
     *
     * @param  array<string, string>  $segments
     */
    private function toEvidenceDraft(string $ref, array $segments): JobAnalysisFindingEvidenceDraft
    {
        return new JobAnalysisFindingEvidenceDraft(
            excerpt: $segments[$ref],
            sourceSection: null,
            sourceLocator: $ref,
        );
    }
}
