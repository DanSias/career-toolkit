<?php

use App\Enums\JobAnalysisEmphasis;
use App\Enums\JobAnalysisFindingCategory;
use App\Enums\JobAnalysisRequirementStrength;
use App\Enums\JobMatchCoverage;
use App\Models\JobAnalysisFinding;
use App\Models\JobMatch;
use App\Support\ResumeVariant\DiscoveryPreflight;
use Tests\Support\ResumeVariantFixtures;

function makeNoEvidenceFinding(JobMatch $match, JobAnalysisRequirementStrength $strength, JobAnalysisEmphasis $emphasis, string $label): void
{
    $finding = JobAnalysisFinding::factory()->create([
        'job_analysis_id' => $match->job_analysis_id,
        'category' => JobAnalysisFindingCategory::Technology,
        'label' => $label,
        'statement' => ucfirst($label).' is a technology relevant to the role.',
        'requirement_strength' => $strength,
        'emphasis' => $emphasis,
    ]);

    $match->findings()->create([
        'job_analysis_finding_id' => $finding->id,
        'coverage' => JobMatchCoverage::NoEvidence,
        'coverage_rationale' => 'No evidence found.',
    ]);
}

it('surfaces a required + high-emphasis no_evidence finding as a discovery candidate', function () {
    ['profile' => $profile] = ResumeVariantFixtures::candidate();
    ['analysis' => $analysis] = ResumeVariantFixtures::job();
    $match = JobMatch::factory()->create(['career_profile_id' => $profile->id, 'job_analysis_id' => $analysis->id]);

    makeNoEvidenceFinding($match, JobAnalysisRequirementStrength::Required, JobAnalysisEmphasis::High, 'redis');

    $candidates = (new DiscoveryPreflight)->run($match);

    expect(collect($candidates)->pluck('term'))->toContain('redis');
});

it('does not surface a preferred + normal-emphasis no_evidence finding', function () {
    ['profile' => $profile] = ResumeVariantFixtures::candidate();
    ['analysis' => $analysis] = ResumeVariantFixtures::job();
    $match = JobMatch::factory()->create(['career_profile_id' => $profile->id, 'job_analysis_id' => $analysis->id]);

    makeNoEvidenceFinding($match, JobAnalysisRequirementStrength::Preferred, JobAnalysisEmphasis::Normal, 'graphql');

    $candidates = (new DiscoveryPreflight)->run($match);

    expect(collect($candidates)->pluck('term'))->not->toContain('graphql');
});

it('does not surface a not_assessable finding regardless of requirement strength', function () {
    ['profile' => $profile] = ResumeVariantFixtures::candidate();
    ['analysis' => $analysis] = ResumeVariantFixtures::job();
    $match = JobMatch::factory()->create(['career_profile_id' => $profile->id, 'job_analysis_id' => $analysis->id]);

    $finding = JobAnalysisFinding::factory()->create([
        'job_analysis_id' => $analysis->id,
        'category' => JobAnalysisFindingCategory::Authorization,
        'requirement_strength' => JobAnalysisRequirementStrength::Required,
        'emphasis' => JobAnalysisEmphasis::High,
    ]);
    $match->findings()->create([
        'job_analysis_finding_id' => $finding->id,
        'coverage' => JobMatchCoverage::NotAssessable,
        'coverage_rationale' => 'Outside the assessable domain.',
    ]);

    $candidates = (new DiscoveryPreflight)->run($match);

    expect(collect($candidates)->pluck('job_analysis_finding_id'))->not->toContain($finding->id);
});

it('caps candidates to a small fixed number, ranked required+high first', function () {
    ['profile' => $profile] = ResumeVariantFixtures::candidate();
    ['analysis' => $analysis] = ResumeVariantFixtures::job();
    $match = JobMatch::factory()->create(['career_profile_id' => $profile->id, 'job_analysis_id' => $analysis->id]);

    makeNoEvidenceFinding($match, JobAnalysisRequirementStrength::Required, JobAnalysisEmphasis::High, 'term-a');
    makeNoEvidenceFinding($match, JobAnalysisRequirementStrength::Required, JobAnalysisEmphasis::High, 'term-b');
    makeNoEvidenceFinding($match, JobAnalysisRequirementStrength::Required, JobAnalysisEmphasis::Normal, 'term-c');
    makeNoEvidenceFinding($match, JobAnalysisRequirementStrength::Preferred, JobAnalysisEmphasis::High, 'term-d');
    makeNoEvidenceFinding($match, JobAnalysisRequirementStrength::Required, JobAnalysisEmphasis::Normal, 'term-e');

    $candidates = (new DiscoveryPreflight)->run($match);

    expect($candidates)->toHaveCount(3)
        ->and(collect($candidates)->pluck('term'))->toContain('term-a')
        ->and(collect($candidates)->pluck('term'))->toContain('term-b');
});

it('is skippable — generation never requires calling it', function () {
    // Purely documents the architectural guarantee: DiscoveryPreflight
    // is a standalone, optional, read-only helper never invoked from
    // inside GenerateResumeVariant.
    $contents = file_get_contents(base_path('app/Support/ResumeVariant/GenerateResumeVariant.php'));

    expect($contents)->not->toContain('DiscoveryPreflight');
});
