<?php

use App\Models\JobPosting;
use Tests\Support\JobMatchFixtures;

/**
 * JobMatchController::store() no longer generates a JobMatch
 * synchronously — it only creates/looks up a GenerationAttempt and
 * dispatches GenerateJobMatchJob. That dispatch/duplicate-prevention
 * behavior is covered by
 * tests/Feature/JobMatch/JobMatchControllerAsyncTest.php, and the
 * job's own generate/validate/persist lifecycle by
 * tests/Feature/JobMatch/GenerateJobMatchJobTest.php. See
 * docs/job-match-generation.md "Async Job Match". Only the
 * route-model-binding behavior below is still this route's own concern.
 */
it('returns 404 when the JobAnalysis does not belong to the given JobPosting', function () {
    ['analysis' => $analysis] = JobMatchFixtures::job();
    $unrelatedJob = JobPosting::factory()->create();

    $this->post(route('jobs.analyses.matches.store', [$unrelatedJob, $analysis]))
        ->assertNotFound();
});

it('returns 404 for a nonexistent JobAnalysis', function () {
    $job = JobPosting::factory()->create();

    $this->post("/jobs/{$job->id}/analyses/999999/matches")->assertNotFound();
});
