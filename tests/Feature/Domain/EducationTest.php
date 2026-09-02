<?php

use App\Models\CareerProfile;
use App\Models\Education;

it('lets a career profile own multiple education records', function () {
    $profile = CareerProfile::factory()->create();
    Education::factory()->for($profile)->count(3)->create();

    expect($profile->educations)->toHaveCount(3)
        ->and($profile->educations->every(fn (Education $e) => $e->careerProfile->is($profile)))->toBeTrue();
});

it('allows year-only precision with a nullable start year', function () {
    $education = Education::factory()->create([
        'institution' => 'University of Central Florida',
        'degree' => 'Master of Science',
        'field_of_study' => 'Optics',
        'start_year' => null,
        'end_year' => 2009,
    ]);

    expect($education->start_year)->toBeNull()
        ->and($education->end_year)->toBe(2009);
});

it('deletes education records when their career profile is deleted', function () {
    $profile = CareerProfile::factory()->create();
    $education = Education::factory()->for($profile)->create();

    $profile->delete();

    expect(Education::find($education->id))->toBeNull();
});
