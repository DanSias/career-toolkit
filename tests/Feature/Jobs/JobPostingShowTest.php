<?php

use App\Models\CareerProfile;
use App\Models\JobPosting;
use Inertia\Testing\AssertableInertia as Assert;

it('renders the job detail page with the original description', function () {
    $profile = CareerProfile::factory()->create();
    $job = JobPosting::factory()->for($profile)->create([
        'company' => 'Acme Corp',
        'title' => 'Senior Engineer',
        'location' => 'Remote',
        'source_url' => 'https://example.com/jobs/123',
        'description' => "Responsibilities:\n- Build things\n- Ship things",
    ]);

    $this->get(route('jobs.show', $job))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('jobs/show')
            ->where('job.company', 'Acme Corp')
            ->where('job.title', 'Senior Engineer')
            ->where('job.location', 'Remote')
            ->where('job.source_url', 'https://example.com/jobs/123')
            ->where('job.description', "Responsibilities:\n- Build things\n- Ship things")
        );
});

it('omits location and source URL from the payload when absent, rather than sending empty strings', function () {
    $profile = CareerProfile::factory()->create();
    $job = JobPosting::factory()->for($profile)->create(['location' => null, 'source_url' => null]);

    $this->get(route('jobs.show', $job))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('job.location', null)
            ->where('job.source_url', null)
        );
});

it('returns a 404 for an unknown job id', function () {
    $this->get('/jobs/999999')->assertNotFound();
});
