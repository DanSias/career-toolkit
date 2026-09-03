<?php

use App\Enums\JobAnalysisFindingCategory;
use App\Models\JobAnalysisFinding;
use App\Models\JobMatch;
use App\Support\ResumeVariant\TargetTerminologyBuilder;
use Tests\Support\ResumeVariantFixtures;

it('extracts a clean single-technology term from an atomic "{Term} is ..." finding', function () {
    ['profile' => $profile] = ResumeVariantFixtures::candidate();
    ['analysis' => $analysis, 'azureFinding' => $azureFinding, 'ownershipFinding' => $ownershipFinding] = ResumeVariantFixtures::job();
    $match = JobMatch::factory()->create(['career_profile_id' => $profile->id, 'job_analysis_id' => $analysis->id]);

    $entries = (new TargetTerminologyBuilder)->build($match);

    expect($entries)->toHaveCount(1)
        ->and($entries[0]['term'])->toBe('Azure')
        ->and($entries[0]['job_analysis_finding_id'])->toBe($azureFinding->id)
        ->and($entries[0]['requirement_strength'])->toBe('required')
        ->and($entries[0]['emphasis'])->toBe('high');
});

it('never extracts a term from a non-technology finding, even if its statement contains " is "', function () {
    ['profile' => $profile] = ResumeVariantFixtures::candidate();
    ['analysis' => $analysis] = ResumeVariantFixtures::job();
    JobAnalysisFinding::factory()->create([
        'job_analysis_id' => $analysis->id,
        'category' => JobAnalysisFindingCategory::Responsibility,
        'statement' => 'Ownership is expected across the full stack.',
    ]);
    $match = JobMatch::factory()->create(['career_profile_id' => $profile->id, 'job_analysis_id' => $analysis->id]);

    $entries = (new TargetTerminologyBuilder)->build($match);

    expect(collect($entries)->pluck('term'))->not->toContain('Ownership');
});

it('never extracts a term from a bundled multi-technology finding lacking the atomic "is" shape', function () {
    ['profile' => $profile] = ResumeVariantFixtures::candidate();
    ['analysis' => $analysis] = ResumeVariantFixtures::job();
    JobAnalysisFinding::factory()->create([
        'job_analysis_id' => $analysis->id,
        'category' => JobAnalysisFindingCategory::Technology,
        'label' => 'full_stack_range',
        'statement' => 'Have full-stack range across Python, TypeScript, SQL, REST/GraphQL, and cloud deployment.',
    ]);
    $match = JobMatch::factory()->create(['career_profile_id' => $profile->id, 'job_analysis_id' => $analysis->id]);

    $entries = (new TargetTerminologyBuilder)->build($match);

    expect($entries)->toHaveCount(1); // only the fixture's own atomic Azure finding
});

it('marks direct_evidence_exists false for a term the candidate only has transferable evidence for', function () {
    ['profile' => $profile, 'factAws' => $factAws, 'factIndependent' => $factIndependent] = ResumeVariantFixtures::candidate();
    ['analysis' => $analysis, 'azureFinding' => $azureFinding, 'ownershipFinding' => $ownershipFinding] = ResumeVariantFixtures::job();
    $match = ResumeVariantFixtures::jobMatch($profile, $analysis, $azureFinding, $ownershipFinding, $factAws, $factIndependent);

    $entries = (new TargetTerminologyBuilder)->build($match);

    expect($entries[0]['direct_evidence_exists'])->toBeFalse();
});
