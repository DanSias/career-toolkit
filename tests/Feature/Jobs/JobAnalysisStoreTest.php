<?php

/**
 * JobAnalysisController::store() no longer generates a JobAnalysis
 * synchronously — it only creates/looks up a GenerationAttempt and
 * dispatches GenerateJobAnalysisJob. That dispatch/duplicate-prevention
 * behavior is covered by
 * tests/Feature/JobAnalysis/JobAnalysisControllerAsyncTest.php, and the
 * job's own generate/validate/persist lifecycle by
 * tests/Feature/JobAnalysis/GenerateJobAnalysisJobTest.php. See
 * docs/job-analysis-generation.md "Async Job Analysis". Only the
 * route-model-binding behavior below is still this route's own concern.
 */
it('returns 404 when generating for a nonexistent job posting', function () {
    $this->post('/jobs/999999/analyses')->assertNotFound();
});
