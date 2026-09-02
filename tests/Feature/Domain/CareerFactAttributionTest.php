<?php

use App\Models\CareerFact;
use App\Models\CareerProfile;
use App\Models\Employer;
use App\Models\Project;
use App\Models\Role;

it('attributes a career fact to the career profile itself', function () {
    $profile = CareerProfile::factory()->create();

    $fact = CareerFact::factory()->for($profile)->create([
        'attributable_type' => $profile->getMorphClass(),
        'attributable_id' => $profile->id,
    ]);

    expect($fact->attributable)->toBeInstanceOf(CareerProfile::class)
        ->and($fact->attributable->is($profile))->toBeTrue();
});

it('attributes a career fact to an employer', function () {
    $profile = CareerProfile::factory()->create();
    $employer = Employer::factory()->for($profile)->create();

    $fact = CareerFact::factory()->for($profile)->create([
        'attributable_type' => $employer->getMorphClass(),
        'attributable_id' => $employer->id,
    ]);

    expect($fact->attributable)->toBeInstanceOf(Employer::class)
        ->and($fact->attributable->is($employer))->toBeTrue()
        ->and($employer->careerFacts->first()->is($fact))->toBeTrue();
});

it('attributes a career fact to a role', function () {
    $profile = CareerProfile::factory()->create();
    $role = Role::factory()->for(Employer::factory()->for($profile))->create();

    $fact = CareerFact::factory()->for($profile)->create([
        'attributable_type' => $role->getMorphClass(),
        'attributable_id' => $role->id,
    ]);

    expect($fact->attributable)->toBeInstanceOf(Role::class)
        ->and($fact->attributable->is($role))->toBeTrue()
        ->and($role->careerFacts->first()->is($fact))->toBeTrue();
});

it('attributes a career fact to a project', function () {
    $profile = CareerProfile::factory()->create();
    $project = Project::factory()
        ->for(Role::factory()->for(Employer::factory()->for($profile)))
        ->create();

    $fact = CareerFact::factory()->for($profile)->create([
        'attributable_type' => $project->getMorphClass(),
        'attributable_id' => $project->id,
    ]);

    expect($fact->attributable)->toBeInstanceOf(Project::class)
        ->and($fact->attributable->is($project))->toBeTrue()
        ->and($project->careerFacts->first()->is($fact))->toBeTrue();
});

it('exposes career_profile_id and attributable as two distinct relations that must resolve to the same profile', function () {
    $profile = CareerProfile::factory()->create();
    $project = Project::factory()
        ->for(Role::factory()->for(Employer::factory()->for($profile)))
        ->create();

    // A fact attributed to a Project still belongs to a CareerProfile
    // directly (`career_profile_id`) rather than only reachable through
    // the polymorphic target — these are two separate relations. Since
    // the attribution-integrity pass, they're also required to agree:
    // see AttributionIntegrityTest.php for the cross-profile rejection
    // cases this enables.
    $fact = CareerFact::factory()->for($profile)->create([
        'attributable_type' => $project->getMorphClass(),
        'attributable_id' => $project->id,
    ]);

    expect($fact->careerProfile->is($profile))->toBeTrue()
        ->and($fact->attributable->is($project))->toBeTrue()
        ->and($profile->careerFacts()->whereKey($fact->id)->exists())->toBeTrue();
});

it('does not require a career fact to be attributed to a project', function () {
    $profile = CareerProfile::factory()->create();
    $employer = Employer::factory()->for($profile)->create();

    $fact = CareerFact::factory()->for($profile)->create([
        'attributable_type' => $employer->getMorphClass(),
        'attributable_id' => $employer->id,
    ]);

    expect($fact->attributable)->not->toBeInstanceOf(Project::class);
});
