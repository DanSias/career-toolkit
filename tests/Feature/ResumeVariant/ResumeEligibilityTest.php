<?php

use App\Enums\Visibility;
use App\Models\CareerFact;
use App\Models\CareerProfile;
use App\Models\Employer;
use App\Models\Project;
use App\Models\Role;
use App\Support\ResumeVariant\ResumeEligibility;

it('treats a Public CareerFact as eligible', function () {
    $fact = CareerFact::factory()->create(['visibility' => Visibility::Public]);

    expect(ResumeEligibility::isEligible($fact))->toBeTrue();
});

it('treats a Restricted CareerFact as eligible', function () {
    $fact = CareerFact::factory()->create(['visibility' => Visibility::Restricted]);

    expect(ResumeEligibility::isEligible($fact))->toBeTrue();
});

it('excludes a Private CareerFact', function () {
    $fact = CareerFact::factory()->create(['visibility' => Visibility::Private]);

    expect(ResumeEligibility::isEligible($fact))->toBeFalse();
});

it('excludes a fact attributed to a Project with Private default_visibility even when the fact itself is Public', function () {
    $employer = Employer::factory()->create();
    $role = Role::factory()->create(['employer_id' => $employer->id]);
    $project = Project::factory()->create(['role_id' => $role->id, 'default_visibility' => Visibility::Private]);

    $fact = CareerFact::factory()->create([
        'career_profile_id' => $employer->career_profile_id,
        'visibility' => Visibility::Public,
        'attributable_type' => (new Project)->getMorphClass(),
        'attributable_id' => $project->id,
    ]);

    expect(ResumeEligibility::isEligible($fact))->toBeFalse();
});

it('includes a fact attributed to a Project with a non-Private (or null) default_visibility', function () {
    $employer = Employer::factory()->create();
    $role = Role::factory()->create(['employer_id' => $employer->id]);
    $project = Project::factory()->create(['role_id' => $role->id, 'default_visibility' => null]);

    $fact = CareerFact::factory()->create([
        'career_profile_id' => $employer->career_profile_id,
        'visibility' => Visibility::Restricted,
        'attributable_type' => (new Project)->getMorphClass(),
        'attributable_id' => $project->id,
    ]);

    expect(ResumeEligibility::isEligible($fact))->toBeTrue();
});

it('scopes eligibleCareerFactsQuery to exactly the eligible facts for one CareerProfile', function () {
    $profile = CareerProfile::factory()->create();
    $public = CareerFact::factory()->create(['career_profile_id' => $profile->id, 'visibility' => Visibility::Public]);
    $restricted = CareerFact::factory()->create(['career_profile_id' => $profile->id, 'visibility' => Visibility::Restricted]);
    $private = CareerFact::factory()->create(['career_profile_id' => $profile->id, 'visibility' => Visibility::Private]);

    // A fact from a different profile must never leak in.
    $otherProfileFact = CareerFact::factory()->create(['visibility' => Visibility::Public]);

    $eligibleIds = ResumeEligibility::eligibleCareerFactsQuery($profile)->pluck('id')->all();

    expect($eligibleIds)->toContain($public->id)
        ->and($eligibleIds)->toContain($restricted->id)
        ->and($eligibleIds)->not->toContain($private->id)
        ->and($eligibleIds)->not->toContain($otherProfileFact->id);
});
