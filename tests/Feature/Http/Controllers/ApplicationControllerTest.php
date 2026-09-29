<?php

use App\Models\Application;
use App\Models\JobPosting;
use App\Models\WorkflowRun;

it('dispatches an inspection and redirects to the application show page', function () {
    $jobPosting = JobPosting::factory()->create(['source_url' => 'https://job-boards.greenhouse.io/acme/jobs/1']);

    $response = $this->post(route('jobs.applications.store', $jobPosting));

    $application = Application::firstWhere('job_posting_id', $jobPosting->id);
    expect($application)->not->toBeNull();
    $response->assertRedirect(route('applications.show', $application));
    expect(WorkflowRun::count())->toBe(1);
});

it('returns a clean 422 for a job posting with no application URL, creating no state', function () {
    $jobPosting = JobPosting::factory()->create(['source_url' => null]);

    $this->post(route('jobs.applications.store', $jobPosting))->assertStatus(422);

    expect(Application::count())->toBe(0);
});

it('a double dispatch redirects to the same Application without creating a second one', function () {
    $jobPosting = JobPosting::factory()->create(['source_url' => 'https://example.com']);

    $this->post(route('jobs.applications.store', $jobPosting));
    $this->post(route('jobs.applications.store', $jobPosting));

    expect(Application::count())->toBe(1)
        ->and(WorkflowRun::count())->toBe(1);
});

it('shows the application inspection page with initial state', function () {
    $jobPosting = JobPosting::factory()->create(['source_url' => 'https://example.com']);
    $application = Application::factory()->for($jobPosting, 'jobPosting')->create();

    $response = $this->get(route('applications.show', $application));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('applications/show')
        ->where('application.id', $application->id)
        ->where('application.job_posting.id', $jobPosting->id)
        ->where('inspection.application_id', $application->id)
        ->where('inspection.workflow_run', null)
        ->where('inspection.latest_result', null));
});

it('exposes application_id on the JobPosting show page once one exists', function () {
    $jobPosting = JobPosting::factory()->create();
    $application = Application::factory()->for($jobPosting, 'jobPosting')->create();

    $response = $this->get(route('jobs.show', $jobPosting));

    $response->assertInertia(fn ($page) => $page->where('job.application_id', $application->id));
});

it('exposes a null application_id on the JobPosting show page when none exists yet', function () {
    $jobPosting = JobPosting::factory()->create();

    $response = $this->get(route('jobs.show', $jobPosting));

    $response->assertInertia(fn ($page) => $page->where('job.application_id', null));
});
