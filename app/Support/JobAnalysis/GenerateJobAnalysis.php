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
use App\Support\JobAnalysis\Prompts\JobAnalysisPromptV2;
use Illuminate\Support\Facades\DB;

/**
 * The whole generation pipeline for one JobPosting, start to finish:
 *
 *   prompt -> provider -> validate -> verify evidence -> trusted draft
 *   -> one atomic transaction creating JobAnalysis + Findings + Evidence
 *
 * Everything before the transaction can fail loudly without touching
 * the database at all. Nothing about the JobPosting except its
 * company/title/location/description ever leaves this class — no
 * CareerFact, Skill, Project, Employer, or Role is referenced anywhere
 * in this pipeline. See docs/job-analysis-generation.md.
 */
final class GenerateJobAnalysis
{
    public function __construct(
        private readonly GeneratesJobAnalysis $provider,
        private readonly JobAnalysisPromptV2 $prompt,
        private readonly JobAnalysisResponseValidator $validator,
        private readonly EvidenceExcerptVerifier $evidenceVerifier,
    ) {}

    /**
     * Generates and persists a new, immutable JobAnalysis snapshot for
     * the given JobPosting. Always creates a new snapshot — never
     * updates a prior one, even if one already exists for this posting.
     *
     * @throws JobAnalysisProviderException on provider/transport failure
     * @throws InvalidJobAnalysisResponseException on validation or evidence-verification failure
     */
    public function generate(JobPosting $posting): JobAnalysis
    {
        $providerResponse = $this->provider->generate(
            $this->prompt->systemPrompt(),
            $this->prompt->userPrompt($posting),
            $this->prompt->jsonSchema(),
        );

        $validated = $this->validator->validate($providerResponse->structuredContent);

        $this->evidenceVerifier->verify($validated, $posting->description);

        $draft = $this->toDraft($validated);

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
     */
    private function toDraft(array $validated): JobAnalysisDraft
    {
        /** @var array<int, array<string, mixed>> $findings */
        $findings = $validated['findings'];

        return new JobAnalysisDraft(
            roleSummary: $validated['role_summary'],
            overallSeniority: JobAnalysisSeniority::from($validated['overall_seniority']),
            seniorityRationale: $validated['seniority_rationale'],
            findings: array_map($this->toFindingDraft(...), $findings),
        );
    }

    /**
     * @param  array<string, mixed>  $finding
     */
    private function toFindingDraft(array $finding): JobAnalysisFindingDraft
    {
        /** @var array<int, array<string, mixed>> $evidence */
        $evidence = $finding['evidence'];

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
            evidence: array_map($this->toEvidenceDraft(...), $evidence),
        );
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    private function toEvidenceDraft(array $entry): JobAnalysisFindingEvidenceDraft
    {
        return new JobAnalysisFindingEvidenceDraft(
            excerpt: $entry['excerpt'],
            sourceSection: $entry['source_section'] ?? null,
            sourceLocator: $entry['source_locator'] ?? null,
        );
    }
}
