<?php

use App\Enums\JobMatchCoverage;
use App\Enums\MatchRelationship;
use App\Enums\Visibility;
use App\Models\JobAnalysis;
use App\Models\JobMatch;
use App\Models\JobPosting;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\JobMatchFixtures;

function persistedMatch(): JobMatch
{
    ['factOne' => $factOne, 'education' => $education, 'profile' => $profile] = JobMatchFixtures::candidate();
    ['analysis' => $analysis, 'findingOne' => $findingOne, 'findingTwo' => $findingTwo] = JobMatchFixtures::job();

    $factOne->update(['visibility' => Visibility::Public]);

    $match = JobMatch::factory()->create([
        'job_analysis_id' => $analysis->id,
        'career_profile_id' => $profile->id,
    ]);

    $matchFindingOne = $match->findings()->create([
        'job_analysis_finding_id' => $findingOne->id,
        'coverage' => JobMatchCoverage::Supported,
        'coverage_rationale' => 'Directly demonstrated by production backend ownership.',
    ]);
    $matchFindingOne->careerFactMatches()->create([
        'career_fact_id' => $factOne->id,
        'relationship' => MatchRelationship::Direct,
        'rationale' => 'Built and owned a production backend service.',
    ]);
    $matchFindingOne->educationMatches()->create([
        'education_id' => $education->id,
        'relationship' => MatchRelationship::Contextual,
        'rationale' => 'Relevant technical education background.',
    ]);

    $match->findings()->create([
        'job_analysis_finding_id' => $findingTwo->id,
        'coverage' => JobMatchCoverage::NoEvidence,
        'coverage_rationale' => 'No candidate evidence addressing container orchestration was found.',
    ]);

    return $match->fresh();
}

it('renders the match detail page with job/analysis context and generation metadata', function () {
    $match = persistedMatch();
    $analysis = JobAnalysis::find($match->job_analysis_id);
    $job = $analysis->jobPosting;

    $this->get(route('jobs.analyses.matches.show', [$job, $analysis, $match]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('jobs/matches/show')
            ->where('job.id', $job->id)
            ->where('analysis.id', $analysis->id)
            ->where('match.id', $match->id)
            ->where('match.schema_version', $match->schema_version)
            ->where('match.prompt_version', $match->prompt_version)
        );
});

it('groups findings by category and includes coverage plus every support reference', function () {
    $match = persistedMatch();
    $analysis = JobAnalysis::find($match->job_analysis_id);
    $job = $analysis->jobPosting;

    $this->get(route('jobs.analyses.matches.show', [$job, $analysis, $match]))
        ->assertInertia(fn (Assert $page) => $page
            ->has('match.categories', 1)
            ->has('match.categories.0.findings', 2)
            ->where('match.categories.0.findings.0.coverage', 'supported')
            ->where('match.categories.0.findings.0.coverage_rationale', 'Directly demonstrated by production backend ownership.')
            ->has('match.categories.0.findings.0.career_fact_matches', 1)
            ->where('match.categories.0.findings.0.career_fact_matches.0.relationship', 'direct')
            ->where('match.categories.0.findings.0.career_fact_matches.0.visibility', 'public')
            ->has('match.categories.0.findings.0.education_matches', 1)
            ->where('match.categories.0.findings.0.education_matches.0.relationship', 'contextual')
            ->where('match.categories.0.findings.1.coverage', 'no_evidence')
            ->has('match.categories.0.findings.1.career_fact_matches', 0)
        );
});

it('404s when the JobMatch does not belong to the given JobAnalysis', function () {
    $match = persistedMatch();
    $analysis = JobAnalysis::find($match->job_analysis_id);
    $job = $analysis->jobPosting;
    ['analysis' => $unrelatedAnalysis] = JobMatchFixtures::job();

    $this->get(route('jobs.analyses.matches.show', [$job, $unrelatedAnalysis, $match]))
        ->assertNotFound();
});

it('404s when the JobAnalysis does not belong to the given JobPosting', function () {
    $match = persistedMatch();
    $analysis = JobAnalysis::find($match->job_analysis_id);
    $unrelatedJob = JobPosting::factory()->create();

    $this->get(route('jobs.analyses.matches.show', [$unrelatedJob, $analysis, $match]))
        ->assertNotFound();
});

it('404s for a nonexistent JobMatch id', function () {
    ['analysis' => $analysis] = JobMatchFixtures::job();
    $job = $analysis->jobPosting;

    $this->get("/jobs/{$job->id}/analyses/{$analysis->id}/matches/999999")
        ->assertNotFound();
});

it('lists existing matches newest first on the analysis detail page', function () {
    ['factOne' => $factOne, 'education' => $education, 'profile' => $profile] = JobMatchFixtures::candidate();
    ['analysis' => $analysis] = JobMatchFixtures::job();
    $job = $analysis->jobPosting;

    // JobMatch is immutable — ordering is controlled via
    // generated_at at creation time, never a later update.
    $older = JobMatch::factory()->create([
        'job_analysis_id' => $analysis->id,
        'career_profile_id' => $profile->id,
        'generated_at' => now()->subDay(),
    ]);
    $newer = JobMatch::factory()->create([
        'job_analysis_id' => $analysis->id,
        'career_profile_id' => $profile->id,
        'generated_at' => now(),
    ]);

    $this->get(route('jobs.analyses.show', [$job, $analysis]))
        ->assertInertia(fn (Assert $page) => $page
            ->has('analysis.matches', 2)
            ->where('analysis.matches.0.id', $newer->id)
            ->where('analysis.matches.1.id', $older->id)
        );
});
