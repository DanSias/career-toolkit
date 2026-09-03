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

/**
 * Guards the Option A fix: TargetTerminologyBuilder now consults the
 * structured `label` first, with a deliberately structural (not
 * semantic) acceptance rule — exactly one underscore-delimited token,
 * confirmed present verbatim in the finding's own `statement` — before
 * falling back to the pre-existing atomic "{Term} is ..." statement
 * parse. Cases mirror the real Pearly findings discovered during live
 * evaluation: docs/resume-variant-generation.md "Live evaluation".
 */
it('extracts SQL from a clean single-token label paired with an imperative statement', function () {
    ['profile' => $profile] = ResumeVariantFixtures::candidate();
    ['analysis' => $analysis] = ResumeVariantFixtures::job();
    $finding = JobAnalysisFinding::factory()->create([
        'job_analysis_id' => $analysis->id,
        'category' => JobAnalysisFindingCategory::Technology,
        'label' => 'sql',
        'statement' => 'Use SQL for production-grade development and backend/data systems.',
    ]);
    $match = JobMatch::factory()->create(['career_profile_id' => $profile->id, 'job_analysis_id' => $analysis->id]);

    $entries = (new TargetTerminologyBuilder)->build($match);
    $entry = collect($entries)->firstWhere('job_analysis_finding_id', $finding->id);

    expect($entry['term'])->toBe('SQL');
});

it('extracts React from a clean single-token label paired with an imperative statement', function () {
    ['profile' => $profile] = ResumeVariantFixtures::candidate();
    ['analysis' => $analysis] = ResumeVariantFixtures::job();
    $finding = JobAnalysisFinding::factory()->create([
        'job_analysis_id' => $analysis->id,
        'category' => JobAnalysisFindingCategory::Technology,
        'label' => 'react',
        'statement' => 'Be comfortable working in React.',
    ]);
    $match = JobMatch::factory()->create(['career_profile_id' => $profile->id, 'job_analysis_id' => $analysis->id]);

    $entries = (new TargetTerminologyBuilder)->build($match);
    $entry = collect($entries)->firstWhere('job_analysis_finding_id', $finding->id);

    expect($entry['term'])->toBe('React');
});

it('rejects a label with a trailing qualifier rather than stripping it (typescript_production)', function () {
    ['profile' => $profile] = ResumeVariantFixtures::candidate();
    ['analysis' => $analysis] = ResumeVariantFixtures::job();
    $finding = JobAnalysisFinding::factory()->create([
        'job_analysis_id' => $analysis->id,
        'category' => JobAnalysisFindingCategory::Technology,
        'label' => 'typescript_production',
        'statement' => 'Use TypeScript for production-grade development.',
    ]);
    $match = JobMatch::factory()->create(['career_profile_id' => $profile->id, 'job_analysis_id' => $analysis->id]);

    $entries = (new TargetTerminologyBuilder)->build($match);

    expect(collect($entries)->pluck('job_analysis_finding_id'))->not->toContain($finding->id);
});

it('rejects a disjunctive category label (major_cloud_provider)', function () {
    ['profile' => $profile] = ResumeVariantFixtures::candidate();
    ['analysis' => $analysis] = ResumeVariantFixtures::job();
    $finding = JobAnalysisFinding::factory()->create([
        'job_analysis_id' => $analysis->id,
        'category' => JobAnalysisFindingCategory::Technology,
        'label' => 'major_cloud_provider',
        'statement' => 'Be familiar with a major cloud provider: GCP, AWS, or Azure.',
    ]);
    $match = JobMatch::factory()->create(['career_profile_id' => $profile->id, 'job_analysis_id' => $analysis->id]);

    $entries = (new TargetTerminologyBuilder)->build($match);

    expect(collect($entries)->pluck('job_analysis_finding_id'))->not->toContain($finding->id);
});

it('rejects a conjunctive compound label and never delimiter-splits it into several terms (cicd_github_actions_terraform)', function () {
    ['profile' => $profile] = ResumeVariantFixtures::candidate();
    ['analysis' => $analysis] = ResumeVariantFixtures::job();
    $finding = JobAnalysisFinding::factory()->create([
        'job_analysis_id' => $analysis->id,
        'category' => JobAnalysisFindingCategory::Technology,
        'label' => 'cicd_github_actions_terraform',
        'statement' => 'Work with CI/CD, GitHub Actions, and Terraform as needed.',
    ]);
    $match = JobMatch::factory()->create(['career_profile_id' => $profile->id, 'job_analysis_id' => $analysis->id]);

    $entries = (new TargetTerminologyBuilder)->build($match);

    expect(collect($entries)->pluck('job_analysis_finding_id'))->not->toContain($finding->id)
        ->and(collect($entries)->pluck('term'))
        ->not->toContain('CI/CD')
        ->not->toContain('GitHub Actions')
        ->not->toContain('Terraform');
});

it('still falls back to the atomic "{Term} is ..." statement shape when label does not yield a term', function () {
    ['profile' => $profile] = ResumeVariantFixtures::candidate();
    ['analysis' => $analysis] = ResumeVariantFixtures::job();
    $finding = JobAnalysisFinding::factory()->create([
        'job_analysis_id' => $analysis->id,
        'category' => JobAnalysisFindingCategory::Technology,
        'label' => null,
        'statement' => 'Python is a programming language relevant to the role.',
    ]);
    $match = JobMatch::factory()->create(['career_profile_id' => $profile->id, 'job_analysis_id' => $analysis->id]);

    $entries = (new TargetTerminologyBuilder)->build($match);
    $entry = collect($entries)->firstWhere('job_analysis_finding_id', $finding->id);

    expect($entry['term'])->toBe('Python');
});

it('deterministically deduplicates the same term extracted from two different findings', function () {
    ['profile' => $profile] = ResumeVariantFixtures::candidate();
    ['analysis' => $analysis] = ResumeVariantFixtures::job();
    $firstFinding = JobAnalysisFinding::factory()->create([
        'job_analysis_id' => $analysis->id,
        'category' => JobAnalysisFindingCategory::Technology,
        'label' => 'sql',
        'statement' => 'Use SQL for production-grade development.',
    ]);
    JobAnalysisFinding::factory()->create([
        'job_analysis_id' => $analysis->id,
        'category' => JobAnalysisFindingCategory::Technology,
        'label' => null,
        'statement' => 'SQL is required for reporting work.',
    ]);
    $match = JobMatch::factory()->create(['career_profile_id' => $profile->id, 'job_analysis_id' => $analysis->id]);

    $entries = (new TargetTerminologyBuilder)->build($match);
    $sqlEntries = collect($entries)->where('term', 'SQL');

    expect($sqlEntries)->toHaveCount(1)
        ->and($sqlEntries->first()['job_analysis_finding_id'])->toBe($firstFinding->id);
});
