<?php

namespace Tests\Support;

use App\Models\CareerFact;
use App\Models\CareerProfile;
use App\Models\Education;
use App\Models\JobAnalysis;
use App\Models\JobAnalysisFinding;

/**
 * Shared, self-consistent fixtures for JobMatch tests: a small
 * candidate side (CareerProfile + two CareerFacts + one Education row)
 * and a small job side (JobAnalysis + two findings), plus a matching
 * valid provider-response payload referencing their real keys/ids —
 * so tests aren't each hand-rolling a slightly different fixture.
 */
final class JobMatchFixtures
{
    /**
     * @return array{profile: CareerProfile, factOne: CareerFact, factTwo: CareerFact, education: Education}
     */
    public static function candidate(): array
    {
        $profile = CareerProfile::factory()->create();

        $factOne = CareerFact::factory()->create([
            'career_profile_id' => $profile->id,
            'key' => 'fixture-backend-ownership',
            'statement' => 'Built and owned a production Laravel backend service handling payment transaction data.',
        ]);

        $factTwo = CareerFact::factory()->create([
            'career_profile_id' => $profile->id,
            'key' => 'fixture-independent-implementation',
            'statement' => 'Independently implemented the technical solution from team-provided requirements.',
        ]);

        $education = Education::factory()->create([
            'career_profile_id' => $profile->id,
            'degree' => 'Bachelor of Science',
            'field_of_study' => 'Computer Science',
        ]);

        return [
            'profile' => $profile,
            'factOne' => $factOne,
            'factTwo' => $factTwo,
            'education' => $education,
        ];
    }

    /**
     * @return array{analysis: JobAnalysis, findingOne: JobAnalysisFinding, findingTwo: JobAnalysisFinding}
     */
    public static function job(): array
    {
        $analysis = JobAnalysis::factory()->create();

        $findingOne = JobAnalysisFinding::factory()->create([
            'job_analysis_id' => $analysis->id,
            'statement' => '5+ years building production backend systems.',
        ]);

        $findingTwo = JobAnalysisFinding::factory()->create([
            'job_analysis_id' => $analysis->id,
            'statement' => 'Experience with container orchestration (Kubernetes).',
        ]);

        return [
            'analysis' => $analysis,
            'findingOne' => $findingOne,
            'findingTwo' => $findingTwo,
        ];
    }

    /**
     * A valid response covering both fixture findings: the first
     * `supported` by both a CareerFact and the Education row, the
     * second `no_evidence`.
     *
     * @return array<string, mixed>
     */
    public static function validResponse(
        JobAnalysisFinding $findingOne,
        JobAnalysisFinding $findingTwo,
        CareerFact $factOne,
        Education $education,
    ): array {
        return [
            'findings' => [
                [
                    'job_analysis_finding_id' => $findingOne->id,
                    'coverage' => 'supported',
                    'coverage_rationale' => 'Directly demonstrated by production backend ownership.',
                    'matches' => [
                        [
                            'career_fact_key' => $factOne->key,
                            'relationship' => 'direct',
                            'rationale' => 'Built and owned a production backend service.',
                        ],
                    ],
                    'education_matches' => [
                        [
                            'education_id' => $education->id,
                            'relationship' => 'contextual',
                            'rationale' => 'Relevant technical education background.',
                        ],
                    ],
                ],
                [
                    'job_analysis_finding_id' => $findingTwo->id,
                    'coverage' => 'no_evidence',
                    'coverage_rationale' => 'No candidate evidence addressing container orchestration was found.',
                    'matches' => [],
                    'education_matches' => [],
                ],
            ],
        ];
    }
}
