<?php

use App\Models\CareerProfile;
use App\Models\JobPosting;

it('renders the add job page successfully', function () {
    CareerProfile::factory()->create();

    $this->get(route('jobs.create'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('jobs/create'));
});

it('creates a job posting with valid input and redirects to its detail page', function () {
    $profile = CareerProfile::factory()->create();

    $response = $this->post(route('jobs.store'), [
        'company' => 'Acme Corp',
        'title' => 'Senior Engineer',
        'location' => 'Remote',
        'source_url' => 'https://example.com/jobs/123',
        'description' => "Line one.\nLine two.\n\nLine four.",
    ]);

    $job = JobPosting::sole();

    $response->assertRedirect(route('jobs.show', $job));
    expect($job->career_profile_id)->toBe($profile->id)
        ->and($job->company)->toBe('Acme Corp')
        ->and($job->title)->toBe('Senior Engineer')
        ->and($job->location)->toBe('Remote')
        ->and($job->source_url)->toBe('https://example.com/jobs/123')
        ->and($job->description)->toBe("Line one.\nLine two.\n\nLine four.");
});

it('creates a job posting with optional fields omitted', function () {
    CareerProfile::factory()->create();

    $this->post(route('jobs.store'), [
        'company' => 'Acme Corp',
        'title' => 'Senior Engineer',
        'description' => 'A description.',
    ])->assertRedirect();

    $job = JobPosting::sole();
    expect($job->location)->toBeNull()
        ->and($job->source_url)->toBeNull();
});

it('requires company, title, and description', function () {
    CareerProfile::factory()->create();

    $this->post(route('jobs.store'), [])
        ->assertSessionHasErrors(['company', 'title', 'description']);

    expect(JobPosting::count())->toBe(0);
});

it('validates the source URL when supplied', function () {
    CareerProfile::factory()->create();

    $this->post(route('jobs.store'), [
        'company' => 'Acme Corp',
        'title' => 'Senior Engineer',
        'description' => 'A description.',
        'source_url' => 'not-a-url',
    ])->assertSessionHasErrors(['source_url']);

    expect(JobPosting::count())->toBe(0);
});

it('preserves leading and trailing whitespace in the job description rather than trimming it', function () {
    CareerProfile::factory()->create();

    $description = "  Leading and trailing space preserved.  \n\nSecond paragraph.  ";

    $this->post(route('jobs.store'), [
        'company' => 'Acme Corp',
        'title' => 'Senior Engineer',
        'description' => $description,
    ])->assertRedirect();

    expect(JobPosting::sole()->description)->toBe($description);
});

it('trims ordinary single-line fields', function () {
    CareerProfile::factory()->create();

    $this->post(route('jobs.store'), [
        'company' => '  Acme Corp  ',
        'title' => '  Senior Engineer  ',
        'description' => 'A description.',
    ])->assertRedirect();

    $job = JobPosting::sole();
    expect($job->company)->toBe('Acme Corp')
        ->and($job->title)->toBe('Senior Engineer');
});
