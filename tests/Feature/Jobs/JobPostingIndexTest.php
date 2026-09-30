<?php

use App\Enums\AgentRunStatus;
use App\Enums\WorkflowStatus;
use App\Models\AgentRun;
use App\Models\Application;
use App\Models\CareerProfile;
use App\Models\JobPosting;
use App\Models\WorkflowRun;
use App\Models\WorkflowStep;
use Inertia\Testing\AssertableInertia as Assert;

it('renders the jobs index successfully', function () {
    CareerProfile::factory()->create();

    $this->get(route('jobs.index'))->assertOk();
});

it('shows the empty state cleanly when no jobs have been captured', function () {
    CareerProfile::factory()->create();

    $this->get(route('jobs.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('jobs/index')->where('jobs', []));
});

it('defaults filters to an empty search and the all status tab', function () {
    CareerProfile::factory()->create();

    $this->get(route('jobs.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('filters.q', '')
            ->where('filters.status', 'all')
        );
});

it('lists jobs newest first', function () {
    $profile = CareerProfile::factory()->create();
    $older = JobPosting::factory()->for($profile)->create(['title' => 'Older Job', 'created_at' => now()->subDays(2)]);
    $newer = JobPosting::factory()->for($profile)->create(['title' => 'Newer Job', 'created_at' => now()]);

    $this->get(route('jobs.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('jobs', 2)
            ->where('jobs.0.id', $newer->id)
            ->where('jobs.1.id', $older->id)
        );
});

it('exposes only summary fields on the index, not the full description', function () {
    $profile = CareerProfile::factory()->create();
    JobPosting::factory()->for($profile)->create(['company' => 'Acme', 'title' => 'Engineer', 'location' => 'Remote']);

    $this->get(route('jobs.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('jobs.0.company', 'Acme')
            ->where('jobs.0.title', 'Engineer')
            ->where('jobs.0.location', 'Remote')
            ->missing('jobs.0.description')
        );
});

it('exposes a not_inspected inspection summary for a JobPosting with no Application', function () {
    $profile = CareerProfile::factory()->create();
    JobPosting::factory()->for($profile)->create();

    $this->get(route('jobs.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('jobs.0.inspection.state', 'not_inspected')
            ->where('jobs.0.inspection.application_id', null)
        );
});

it('exposes an inspected summary with a field count once an inspection has succeeded', function () {
    $profile = CareerProfile::factory()->create();
    $job = JobPosting::factory()->for($profile)->create();
    $application = Application::factory()->for($job, 'jobPosting')->create();
    $workflowRun = WorkflowRun::factory()->for($application)->create(['status' => WorkflowStatus::Succeeded]);
    $step = WorkflowStep::factory()->for($workflowRun, 'workflowRun')->create(['status' => WorkflowStatus::Succeeded]);
    AgentRun::factory()->for($step, 'workflowStep')->create([
        'status' => AgentRunStatus::Succeeded,
        'inspection_outcome' => 'complete',
    ]);

    $this->get(route('jobs.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('jobs.0.inspection.state', 'inspected')
            ->where('jobs.0.inspection.application_id', $application->id)
        );
});

it('filters by title/company search text', function () {
    $profile = CareerProfile::factory()->create();
    JobPosting::factory()->for($profile)->create(['company' => 'Anthropic', 'title' => 'Account Executive']);
    JobPosting::factory()->for($profile)->create(['company' => 'Initech', 'title' => 'TPS Report Auditor']);

    $this->get(route('jobs.index', ['q' => 'anthropic']))
        ->assertInertia(fn (Assert $page) => $page
            ->has('jobs', 1)
            ->where('jobs.0.company', 'Anthropic')
            ->where('filters.q', 'anthropic')
        );
});

it('filters to only not_inspected opportunities on the not_inspected status tab', function () {
    $profile = CareerProfile::factory()->create();
    $uninspected = JobPosting::factory()->for($profile)->create();
    $inspectedJob = JobPosting::factory()->for($profile)->create();
    $application = Application::factory()->for($inspectedJob, 'jobPosting')->create();
    $workflowRun = WorkflowRun::factory()->for($application)->create(['status' => WorkflowStatus::Succeeded]);
    $step = WorkflowStep::factory()->for($workflowRun, 'workflowRun')->create(['status' => WorkflowStatus::Succeeded]);
    AgentRun::factory()->for($step, 'workflowStep')->create(['status' => AgentRunStatus::Succeeded, 'inspection_outcome' => 'complete']);

    $this->get(route('jobs.index', ['status' => 'not_inspected']))
        ->assertInertia(fn (Assert $page) => $page
            ->has('jobs', 1)
            ->where('jobs.0.id', $uninspected->id)
        );
});
