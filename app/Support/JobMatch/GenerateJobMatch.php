<?php

namespace App\Support\JobMatch;

use App\Contracts\GeneratesJobMatch;
use App\Enums\JobMatchCoverage;
use App\Enums\MatchRelationship;
use App\Exceptions\InvalidJobMatchResponseException;
use App\Exceptions\JobMatchProviderException;
use App\Models\CareerFact;
use App\Models\CareerProfile;
use App\Models\JobAnalysis;
use App\Models\JobMatch;
use App\Support\JobMatch\Prompts\JobMatchPromptV1;
use Illuminate\Support\Facades\DB;

/**
 * The whole matching pipeline for one (JobAnalysis, CareerProfile)
 * pair, start to finish:
 *
 *   build candidate+job payloads -> provider -> validate -> trusted
 *   draft -> one atomic transaction creating JobMatch + JobMatchFinding[]
 *   + CareerFactMatch[]/EducationMatch[]
 *
 * Everything before the transaction can fail loudly without touching
 * the database at all. Mirrors
 * App\Support\JobAnalysis\GenerateJobAnalysis exactly, with one
 * structural difference: unlike JobAnalysis generation, this pipeline
 * DELIBERATELY consumes candidate data (CareerFact, Education) — that
 * is its entire purpose. See docs/job-match-generation.md.
 */
final class GenerateJobMatch
{
    public function __construct(
        private readonly GeneratesJobMatch $provider,
        private readonly JobMatchPromptV1 $prompt,
        private readonly CandidatePayloadBuilder $candidateBuilder,
        private readonly JobPayloadBuilder $jobBuilder,
        private readonly JobMatchResponseValidator $validator,
    ) {}

    /**
     * Generates and persists a new, immutable JobMatch snapshot
     * comparing the given JobAnalysis against the given CareerProfile.
     * Always creates a new snapshot — never updates a prior one, even
     * if one already exists for this exact pair.
     *
     * @throws JobMatchProviderException on provider/transport failure
     * @throws InvalidJobMatchResponseException on validation failure
     */
    public function generate(JobAnalysis $analysis, CareerProfile $profile): JobMatch
    {
        $candidatePayload = $this->candidateBuilder->build($profile);
        $jobPayload = $this->jobBuilder->build($analysis);

        /** @var array<int, int> $validFindingIds */
        $validFindingIds = array_column($jobPayload['findings'], 'id');
        /** @var array<int, string> $validFactKeys */
        $validFactKeys = array_column($candidatePayload['career_facts'], 'key');
        /** @var array<int, int> $validEducationIds */
        $validEducationIds = array_column($candidatePayload['education'], 'id');

        $providerResponse = $this->provider->generate(
            $this->prompt->systemPrompt(),
            $this->prompt->userPrompt($candidatePayload, $jobPayload),
            $this->prompt->jsonSchema($validFindingIds, $validFactKeys, $validEducationIds),
        );

        $validated = $this->validator->validate(
            $providerResponse->structuredContent,
            $validFindingIds,
            $validFactKeys,
            $validEducationIds,
        );

        $draft = $this->toDraft($validated);

        $inputSnapshot = [
            'candidate' => $candidatePayload,
            'job' => $jobPayload,
        ];

        return DB::transaction(function () use ($analysis, $profile, $draft, $providerResponse, $validated, $inputSnapshot) {
            $match = JobMatch::create([
                'job_analysis_id' => $analysis->id,
                'career_profile_id' => $profile->id,
                'schema_version' => $this->prompt->schemaVersion(),
                'prompt_version' => $this->prompt->version(),
                'generated_by' => $providerResponse->generatedBy(),
                'generated_at' => now(),
                'input_snapshot' => $inputSnapshot,
                'raw_response' => $validated,
            ]);

            /** @var array<string, int> $factIdsByKey */
            $factIdsByKey = CareerFact::query()
                ->where('career_profile_id', $profile->id)
                ->pluck('id', 'key')
                ->all();

            foreach ($draft->findings as $findingDraft) {
                $matchFinding = $match->findings()->create([
                    'job_analysis_finding_id' => $findingDraft->jobAnalysisFindingId,
                    'coverage' => $findingDraft->coverage,
                    'coverage_rationale' => $findingDraft->coverageRationale,
                ]);

                foreach ($findingDraft->careerFactMatches as $factMatchDraft) {
                    $matchFinding->careerFactMatches()->create([
                        'career_fact_id' => $factIdsByKey[$factMatchDraft->careerFactKey],
                        'relationship' => $factMatchDraft->relationship,
                        'rationale' => $factMatchDraft->rationale,
                    ]);
                }

                foreach ($findingDraft->educationMatches as $educationMatchDraft) {
                    $matchFinding->educationMatches()->create([
                        'education_id' => $educationMatchDraft->educationId,
                        'relationship' => $educationMatchDraft->relationship,
                        'rationale' => $educationMatchDraft->rationale,
                    ]);
                }
            }

            return $match;
        });
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function toDraft(array $validated): JobMatchDraft
    {
        /** @var array<int, array<string, mixed>> $findings */
        $findings = $validated['findings'];

        return new JobMatchDraft(
            findings: array_map($this->toFindingDraft(...), $findings),
        );
    }

    /**
     * @param  array<string, mixed>  $finding
     */
    private function toFindingDraft(array $finding): JobMatchFindingDraft
    {
        /** @var array<int, array<string, mixed>> $matches */
        $matches = $finding['matches'];
        /** @var array<int, array<string, mixed>> $educationMatches */
        $educationMatches = $finding['education_matches'];

        return new JobMatchFindingDraft(
            jobAnalysisFindingId: $finding['job_analysis_finding_id'],
            coverage: JobMatchCoverage::from($finding['coverage']),
            coverageRationale: $finding['coverage_rationale'] ?? null,
            careerFactMatches: array_map($this->toCareerFactMatchDraft(...), $matches),
            educationMatches: array_map($this->toEducationMatchDraft(...), $educationMatches),
        );
    }

    /**
     * @param  array<string, mixed>  $match
     */
    private function toCareerFactMatchDraft(array $match): CareerFactMatchDraft
    {
        return new CareerFactMatchDraft(
            careerFactKey: $match['career_fact_key'],
            relationship: MatchRelationship::from($match['relationship']),
            rationale: $match['rationale'] ?? null,
        );
    }

    /**
     * @param  array<string, mixed>  $match
     */
    private function toEducationMatchDraft(array $match): EducationMatchDraft
    {
        return new EducationMatchDraft(
            educationId: $match['education_id'],
            relationship: MatchRelationship::from($match['relationship']),
            rationale: $match['rationale'] ?? null,
        );
    }
}
