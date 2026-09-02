<?php

use App\Models\CareerProfile;
use App\Models\Employer;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;

it('lets a user own multiple career profiles', function () {
    $user = User::factory()->create();
    $profile = CareerProfile::factory()->for($user)->create();

    expect($user->careerProfiles)->toHaveCount(1)
        ->and($user->careerProfiles->first()->is($profile))->toBeTrue()
        ->and($profile->user->is($user))->toBeTrue();
});

it('lets a career profile own multiple employers', function () {
    $profile = CareerProfile::factory()->create();
    $employers = Employer::factory()->for($profile)->count(3)->create();

    expect($profile->employers)->toHaveCount(3)
        ->and($employers->every(fn (Employer $e) => $e->careerProfile->is($profile)))->toBeTrue();
});

it('lets one employer have multiple successive roles', function () {
    $employer = Employer::factory()->create(['name' => 'Pearson Online Learning Services']);

    $seo = Role::factory()->for($employer)->create([
        'title' => 'SEO Analyst',
        'start_date' => '2013-06-01',
        'end_date' => '2015-09-01',
    ]);

    $lead = Role::factory()->for($employer)->create([
        'title' => 'Data & Analytics Lead Developer',
        'start_date' => '2015-09-01',
        'end_date' => null,
    ]);

    expect($employer->roles)->toHaveCount(2)
        ->and($employer->roles->pluck('title')->all())->toBe([$seo->title, $lead->title])
        ->and($lead->end_date)->toBeNull()
        ->and($seo->end_date->toDateString())->toBe('2015-09-01');
});

it('lets a role have zero or many projects', function () {
    $roleWithNoProjects = Role::factory()->create();
    $roleWithProjects = Role::factory()->create();
    Project::factory()->for($roleWithProjects)->count(2)->create();

    expect($roleWithNoProjects->projects)->toHaveCount(0)
        ->and($roleWithProjects->projects)->toHaveCount(2);
});

it('requires a role start date but allows an open-ended end date for a current role', function () {
    $current = Role::factory()->create(['start_date' => '2025-05-01', 'end_date' => null]);

    expect($current->start_date->toDateString())->toBe('2025-05-01')
        ->and($current->end_date)->toBeNull();
});
