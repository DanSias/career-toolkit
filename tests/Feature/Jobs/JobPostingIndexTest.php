<?php

use App\Models\CareerProfile;
use App\Models\JobPosting;
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
