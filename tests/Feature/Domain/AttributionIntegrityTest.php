<?php

use App\Exceptions\InvalidCareerFactAttributionException;
use App\Models\CareerFact;
use App\Models\CareerProfile;
use App\Models\Employer;
use App\Models\Project;
use App\Models\Role;
use App\Models\Skill;

// --- Valid, same-profile attribution succeeds through the normal write path ---

it('persists a career fact attributed to its own career profile', function () {
    $profile = CareerProfile::factory()->create();

    $fact = CareerFact::factory()->for($profile)->create([
        'attributable_type' => $profile->getMorphClass(),
        'attributable_id' => $profile->id,
    ]);

    expect($fact->exists)->toBeTrue()
        ->and($fact->fresh()->attributable_id)->toBe($profile->id);
});

it('persists a career fact attributed to an employer under the same profile', function () {
    $profile = CareerProfile::factory()->create();
    $employer = Employer::factory()->for($profile)->create();

    $fact = CareerFact::factory()->for($profile)->create([
        'attributable_type' => $employer->getMorphClass(),
        'attributable_id' => $employer->id,
    ]);

    expect($fact->exists)->toBeTrue();
});

it('persists a career fact attributed to a role under the same profile', function () {
    $profile = CareerProfile::factory()->create();
    $role = Role::factory()->for(Employer::factory()->for($profile))->create();

    $fact = CareerFact::factory()->for($profile)->create([
        'attributable_type' => $role->getMorphClass(),
        'attributable_id' => $role->id,
    ]);

    expect($fact->exists)->toBeTrue();
});

it('persists a career fact attributed to a project under the same profile', function () {
    $profile = CareerProfile::factory()->create();
    $project = Project::factory()
        ->for(Role::factory()->for(Employer::factory()->for($profile)))
        ->create();

    $fact = CareerFact::factory()->for($profile)->create([
        'attributable_type' => $project->getMorphClass(),
        'attributable_id' => $project->id,
    ]);

    expect($fact->exists)->toBeTrue();
});

// --- Cross-profile attribution is rejected at the point of save, not merely by a helper ---

it('rejects a career fact attributed to a different career profile', function () {
    $profile = CareerProfile::factory()->create();
    $otherProfile = CareerProfile::factory()->create();

    expect(fn () => CareerFact::factory()->for($profile)->create([
        'attributable_type' => $otherProfile->getMorphClass(),
        'attributable_id' => $otherProfile->id,
    ]))->toThrow(InvalidCareerFactAttributionException::class);

    expect(CareerFact::query()->where('career_profile_id', $profile->id)->count())->toBe(0);
});

it('rejects a career fact attributed to an employer under a different career profile', function () {
    $profile = CareerProfile::factory()->create();
    $otherEmployer = Employer::factory()->create();

    expect(fn () => CareerFact::factory()->for($profile)->create([
        'attributable_type' => $otherEmployer->getMorphClass(),
        'attributable_id' => $otherEmployer->id,
    ]))->toThrow(InvalidCareerFactAttributionException::class);
});

it('rejects a career fact attributed to a role under a different career profile', function () {
    $profile = CareerProfile::factory()->create();
    $otherRole = Role::factory()->create();

    expect(fn () => CareerFact::factory()->for($profile)->create([
        'attributable_type' => $otherRole->getMorphClass(),
        'attributable_id' => $otherRole->id,
    ]))->toThrow(InvalidCareerFactAttributionException::class);
});

it('rejects a career fact attributed to a project under a different career profile', function () {
    $profile = CareerProfile::factory()->create();
    $otherProject = Project::factory()->create();

    expect(fn () => CareerFact::factory()->for($profile)->create([
        'attributable_type' => $otherProject->getMorphClass(),
        'attributable_id' => $otherProject->id,
    ]))->toThrow(InvalidCareerFactAttributionException::class);
});

// --- Unsupported types and nonexistent targets ---

it('rejects an attributable type that is not in the enforced morph map', function () {
    $profile = CareerProfile::factory()->create();
    $skill = Skill::factory()->for($profile)->create();

    // Skill is a real, persisted model, but it was never registered as an
    // attribution target in the morph map — CareerFacts may not be
    // attributed to it.
    expect(fn () => CareerFact::factory()->for($profile)->create([
        'attributable_type' => 'skill',
        'attributable_id' => $skill->id,
    ]))->toThrow(InvalidCareerFactAttributionException::class);
});

it('rejects a wholly unknown attributable type string', function () {
    $profile = CareerProfile::factory()->create();

    expect(fn () => CareerFact::factory()->for($profile)->create([
        'attributable_type' => 'not-a-real-type',
        'attributable_id' => 1,
    ]))->toThrow(InvalidCareerFactAttributionException::class);
});

it('rejects an attribution target that does not exist', function () {
    $profile = CareerProfile::factory()->create();
    $employer = Employer::factory()->for($profile)->create();
    $nonExistentId = $employer->id + 1000;

    expect(fn () => CareerFact::factory()->for($profile)->create([
        'attributable_type' => $employer->getMorphClass(),
        'attributable_id' => $nonExistentId,
    ]))->toThrow(InvalidCareerFactAttributionException::class);
});

// --- Both directions of the invariant are protected on update, not just create ---

it('rejects changing an existing valid attribution to a cross-profile project', function () {
    $profile = CareerProfile::factory()->create();
    $employer = Employer::factory()->for($profile)->create();
    $otherProject = Project::factory()->create();

    $fact = CareerFact::factory()->for($profile)->create([
        'attributable_type' => $employer->getMorphClass(),
        'attributable_id' => $employer->id,
    ]);

    expect(fn () => $fact->update([
        'attributable_type' => $otherProject->getMorphClass(),
        'attributable_id' => $otherProject->id,
    ]))->toThrow(InvalidCareerFactAttributionException::class);

    // The fact keeps its last valid, persisted attribution.
    expect($fact->fresh()->attributable_type)->toBe($employer->getMorphClass())
        ->and($fact->fresh()->attributable_id)->toBe($employer->id);
});

it('rejects moving an existing career fact to a different career profile when that would make its current attribution cross-profile', function () {
    $profile = CareerProfile::factory()->create();
    $otherProfile = CareerProfile::factory()->create();
    $project = Project::factory()
        ->for(Role::factory()->for(Employer::factory()->for($profile)))
        ->create();

    $fact = CareerFact::factory()->for($profile)->create([
        'attributable_type' => $project->getMorphClass(),
        'attributable_id' => $project->id,
    ]);

    // Only career_profile_id changes here — attributable_* is untouched,
    // but re-pointing the fact at $otherProfile would make its existing
    // Project attribution cross-profile. The same saving-time check must
    // catch this, not only changes to attributable_*.
    expect(fn () => $fact->update(['career_profile_id' => $otherProfile->id]))
        ->toThrow(InvalidCareerFactAttributionException::class);

    expect($fact->fresh()->career_profile_id)->toBe($profile->id);
});

it('allows changing career_profile_id together with a matching attribution target', function () {
    $profile = CareerProfile::factory()->create();
    $otherProfile = CareerProfile::factory()->create();
    $otherEmployer = Employer::factory()->for($otherProfile)->create();

    $fact = CareerFact::factory()->for($profile)->create([
        'attributable_type' => $profile->getMorphClass(),
        'attributable_id' => $profile->id,
    ]);

    $fact->update([
        'career_profile_id' => $otherProfile->id,
        'attributable_type' => $otherEmployer->getMorphClass(),
        'attributable_id' => $otherEmployer->id,
    ]);

    expect($fact->fresh()->career_profile_id)->toBe($otherProfile->id)
        ->and($fact->fresh()->attributable_id)->toBe($otherEmployer->id);
});
