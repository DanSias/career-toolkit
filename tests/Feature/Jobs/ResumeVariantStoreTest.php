<?php

use Tests\Support\ResumeVariantFixtures;

/**
 * ResumeVariantController::store() no longer generates a ResumeVariant
 * synchronously — it only creates/looks up a GenerationAttempt and
 * dispatches GenerateResumeVariantJob. That dispatch/duplicate-prevention
 * behavior is covered by
 * tests/Feature/ResumeVariant/ResumeVariantControllerAsyncTest.php, and
 * the job's own generate/validate/persist lifecycle by
 * tests/Feature/ResumeVariant/GenerateResumeVariantJobTest.php. See
 * docs/resume-variant-generation.md "Async Resume". Only the
 * route-model-binding behavior below is still this route's own concern.
 */
it('returns 404 when the JobMatch does not belong to the given JobAnalysis', function () {
    $candidate = ResumeVariantFixtures::candidate();
    $job = ResumeVariantFixtures::job();
    $match = ResumeVariantFixtures::jobMatch(
        $candidate['profile'], $job['analysis'], $job['azureFinding'], $job['ownershipFinding'],
        $candidate['factAws'], $candidate['factIndependent'],
    );
    $jobPosting = $match->jobAnalysis->jobPosting;
    ['analysis' => $unrelatedAnalysis] = ResumeVariantFixtures::job();

    $this->post(route('jobs.analyses.matches.resume.store', [$jobPosting, $unrelatedAnalysis, $match]))
        ->assertNotFound();
});

it('returns 404 for a nonexistent JobMatch', function () {
    $candidate = ResumeVariantFixtures::candidate();
    $job = ResumeVariantFixtures::job();
    $match = ResumeVariantFixtures::jobMatch(
        $candidate['profile'], $job['analysis'], $job['azureFinding'], $job['ownershipFinding'],
        $candidate['factAws'], $candidate['factIndependent'],
    );
    $jobPosting = $match->jobAnalysis->jobPosting;

    $this->post("/jobs/{$jobPosting->id}/analyses/{$match->jobAnalysis->id}/matches/999999/resume")
        ->assertNotFound();
});
