<?php

use App\Models\Application;
use App\Models\CareerProfile;
use App\Models\JobPosting;
use Illuminate\Support\Facades\Schema;

it('belongs to a career profile', function () {
    $profile = CareerProfile::factory()->create();
    $job = JobPosting::factory()->for($profile)->create();

    expect($job->careerProfile->is($profile))->toBeTrue();
});

it('lets a career profile own multiple job postings', function () {
    $profile = CareerProfile::factory()->create();
    JobPosting::factory()->for($profile)->count(3)->create();

    expect($profile->jobPostings)->toHaveCount(3);
});

it('deletes job postings when their career profile is deleted', function () {
    $profile = CareerProfile::factory()->create();
    $job = JobPosting::factory()->for($profile)->create();

    $profile->delete();

    expect(JobPosting::find($job->id))->toBeNull();
});

it('has an application relation, at most one per posting', function () {
    // See tests/Feature/Domain/ApplicationTest.php "allows at most one
    // application per job posting" for the uniqueness invariant itself.
    $job = JobPosting::factory()->create();
    Application::factory()->for($job, 'jobPosting')->create();

    expect($job->applications)->toHaveCount(1);
});

it('stores no analysis, matching, or resume-generation fields', function () {
    // Table columns, not $job->getAttributes() — a freshly-created
    // instance's in-memory attribute bag omits nullable columns that
    // were never explicitly set, so it undercounts the real schema.
    $columns = Schema::getColumnListing('job_postings');

    expect($columns)->toEqualCanonicalizing([
        'id', 'career_profile_id', 'company', 'title', 'source_url', 'location', 'description', 'description_completeness', 'created_at', 'updated_at',
        'discovery_source', 'discovery_source_id', 'canonical_source', 'canonical_source_id',
        'remote_status', 'employment_type',
        'compensation_min', 'compensation_max', 'compensation_currency', 'compensation_interval',
        'posted_at', 'source_updated_at', 'discovered_at', 'status', 'last_checked_at', 'discovery_metadata',
    ]);
});
