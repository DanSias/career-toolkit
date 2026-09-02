<?php

use App\Models\CareerProfile;
use App\Models\JobPosting;

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

it('stores no analysis, matching, or resume-generation fields', function () {
    $job = JobPosting::factory()->create();

    $columns = array_keys($job->getAttributes());

    expect($columns)->toEqualCanonicalizing([
        'id', 'career_profile_id', 'company', 'title', 'source_url', 'location', 'description', 'created_at', 'updated_at',
    ]);
});
