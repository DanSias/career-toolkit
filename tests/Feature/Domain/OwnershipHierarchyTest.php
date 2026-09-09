<?php

use App\Exceptions\InvalidProjectOwnershipException;
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
        'start_year' => 2013,
        'start_month' => 6,
        'end_year' => 2015,
        'end_month' => 9,
    ]);

    $lead = Role::factory()->for($employer)->create([
        'title' => 'Data & Analytics Lead Developer',
        'start_year' => 2015,
        'start_month' => 9,
        'end_year' => null,
        'end_month' => null,
    ]);

    expect($employer->roles)->toHaveCount(2)
        ->and($employer->roles->pluck('title')->all())->toBe([$seo->title, $lead->title])
        ->and($lead->end_year)->toBeNull()
        ->and($seo->end_year)->toBe(2015)
        ->and($seo->end_month)->toBe(9);
});

it('lets a role have zero or many projects', function () {
    $roleWithNoProjects = Role::factory()->create();
    $roleWithProjects = Role::factory()->create();
    Project::factory()->for($roleWithProjects)->count(2)->create();

    expect($roleWithNoProjects->projects)->toHaveCount(0)
        ->and($roleWithProjects->projects)->toHaveCount(2);
});

it('auto-derives a professional Project\'s career_profile_id from its role_id when omitted', function () {
    $profile = CareerProfile::factory()->create();
    $employer = Employer::factory()->for($profile)->create();
    $role = Role::factory()->for($employer)->create();

    $project = Project::factory()->for($role)->create();

    expect($project->career_profile_id)->toBe($profile->id)
        ->and($project->ownerCareerProfileId())->toBe($profile->id);
});

it('requires an independent Project (role_id null) to supply career_profile_id explicitly', function () {
    expect(fn () => Project::factory()->create(['role_id' => null, 'career_profile_id' => null]))
        ->toThrow(InvalidProjectOwnershipException::class);
});

it('lets an independent Project belong directly to a CareerProfile with no Role at all', function () {
    $profile = CareerProfile::factory()->create();

    $project = Project::factory()->create(['role_id' => null, 'career_profile_id' => $profile->id]);

    expect($project->role_id)->toBeNull()
        ->and($project->career_profile_id)->toBe($profile->id)
        ->and($project->ownerCareerProfileId())->toBe($profile->id)
        ->and($project->role)->toBeNull();
});

it('rejects a Project whose explicit career_profile_id does not match its role_id\'s real owning CareerProfile', function () {
    $ownProfile = CareerProfile::factory()->create();
    $otherProfile = CareerProfile::factory()->create();
    $employer = Employer::factory()->for($ownProfile)->create();
    $role = Role::factory()->for($employer)->create();

    expect(fn () => Project::factory()->create(['role_id' => $role->id, 'career_profile_id' => $otherProfile->id]))
        ->toThrow(InvalidProjectOwnershipException::class);
});

it('rejects a Project referencing a role_id that does not exist', function () {
    $profile = CareerProfile::factory()->create();

    expect(fn () => Project::factory()->create(['role_id' => 999999, 'career_profile_id' => $profile->id]))
        ->toThrow(InvalidProjectOwnershipException::class);
});

it('requires a role start year but allows an open-ended end for a current role', function () {
    $current = Role::factory()->create([
        'start_year' => 2025,
        'start_month' => 5,
        'end_year' => null,
        'end_month' => null,
    ]);

    expect($current->start_year)->toBe(2025)
        ->and($current->start_month)->toBe(5)
        ->and($current->end_year)->toBeNull()
        ->and($current->end_month)->toBeNull();
});

it('allows a role start year with no month, for year-only evidence', function () {
    $role = Role::factory()->create(['start_year' => 2013, 'start_month' => null]);

    expect($role->start_year)->toBe(2013)
        ->and($role->start_month)->toBeNull();
});
