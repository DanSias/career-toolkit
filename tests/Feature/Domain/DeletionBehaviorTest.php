<?php

use App\Models\CareerFact;
use App\Models\CareerProfile;
use App\Models\Employer;
use App\Models\Evidence;
use App\Models\Metric;
use App\Models\Project;
use App\Models\Role;
use App\Models\Skill;
use Illuminate\Support\Facades\DB;

it('cascades a career profile deletion through its whole owned hierarchy', function () {
    $profile = CareerProfile::factory()->create();
    $employer = Employer::factory()->for($profile)->create();
    $role = Role::factory()->for($employer)->create();
    $project = Project::factory()->for($role)->create();
    $skill = Skill::factory()->for($profile)->create();

    $fact = CareerFact::factory()->for($profile)->create([
        'attributable_type' => $project->getMorphClass(),
        'attributable_id' => $project->id,
    ]);
    $fact->skills()->attach($skill);
    Evidence::factory()->for($fact)->create();
    Metric::factory()->for($fact)->create();

    $profile->delete();

    expect(Employer::find($employer->id))->toBeNull()
        ->and(Role::find($role->id))->toBeNull()
        ->and(Project::find($project->id))->toBeNull()
        ->and(Skill::find($skill->id))->toBeNull()
        ->and(CareerFact::find($fact->id))->toBeNull()
        ->and(Evidence::where('career_fact_id', $fact->id)->exists())->toBeFalse()
        ->and(Metric::where('career_fact_id', $fact->id)->exists())->toBeFalse();
});

it('deletes a role\'s projects when the employer is deleted', function () {
    $employer = Employer::factory()->create();
    $role = Role::factory()->for($employer)->create();
    $project = Project::factory()->for($role)->create();

    $employer->delete();

    expect(Role::find($role->id))->toBeNull()
        ->and(Project::find($project->id))->toBeNull();
});

it('deletes a role\'s projects when the role is deleted', function () {
    $role = Role::factory()->create();
    $project = Project::factory()->for($role)->create();

    $role->delete();

    expect(Project::find($project->id))->toBeNull();
});

it('reassigns a career fact to its career profile instead of destroying it when the attributed project is deleted', function () {
    $profile = CareerProfile::factory()->create();
    $project = Project::factory()
        ->for(Role::factory()->for(Employer::factory()->for($profile)))
        ->create();

    $fact = CareerFact::factory()->for($profile)->create([
        'attributable_type' => $project->getMorphClass(),
        'attributable_id' => $project->id,
    ]);

    $project->delete();
    $fact->refresh();

    expect(CareerFact::find($fact->id))->not->toBeNull()
        ->and($fact->attributable_type)->toBe($profile->getMorphClass())
        ->and($fact->attributable_id)->toBe($profile->id)
        ->and($fact->attributable)->toBeInstanceOf(CareerProfile::class)
        ->and($fact->attributable->is($profile))->toBeTrue();
});

it('reassigns career facts attributed to a role and to its projects when the role is deleted', function () {
    $profile = CareerProfile::factory()->create();
    $employer = Employer::factory()->for($profile)->create();
    $role = Role::factory()->for($employer)->create();
    $project = Project::factory()->for($role)->create();

    $factOnRole = CareerFact::factory()->for($profile)->create([
        'attributable_type' => $role->getMorphClass(),
        'attributable_id' => $role->id,
    ]);
    $factOnProject = CareerFact::factory()->for($profile)->create([
        'attributable_type' => $project->getMorphClass(),
        'attributable_id' => $project->id,
    ]);

    $role->delete();
    $factOnRole->refresh();
    $factOnProject->refresh();

    expect($factOnRole->attributable)->toBeInstanceOf(CareerProfile::class)
        ->and($factOnRole->attributable->is($profile))->toBeTrue()
        ->and($factOnProject->attributable)->toBeInstanceOf(CareerProfile::class)
        ->and($factOnProject->attributable->is($profile))->toBeTrue();
});

it('reassigns career facts attributed anywhere in an employer\'s hierarchy when the employer is deleted', function () {
    $profile = CareerProfile::factory()->create();
    $employer = Employer::factory()->for($profile)->create();
    $role = Role::factory()->for($employer)->create();
    $project = Project::factory()->for($role)->create();

    $factOnEmployer = CareerFact::factory()->for($profile)->create([
        'attributable_type' => $employer->getMorphClass(),
        'attributable_id' => $employer->id,
    ]);
    $factOnRole = CareerFact::factory()->for($profile)->create([
        'attributable_type' => $role->getMorphClass(),
        'attributable_id' => $role->id,
    ]);
    $factOnProject = CareerFact::factory()->for($profile)->create([
        'attributable_type' => $project->getMorphClass(),
        'attributable_id' => $project->id,
    ]);

    $employer->delete();

    foreach ([$factOnEmployer, $factOnRole, $factOnProject] as $fact) {
        $fact->refresh();
        expect($fact->attributable)->toBeInstanceOf(CareerProfile::class)
            ->and($fact->attributable->is($profile))->toBeTrue();
    }
});

it('removes pivot rows but not the skill itself when a career fact is deleted', function () {
    $skill = Skill::factory()->create();
    $fact = CareerFact::factory()->create();
    $fact->skills()->attach($skill);

    $fact->delete();

    expect(Skill::find($skill->id))->not->toBeNull()
        ->and(DB::table('career_fact_skill')->where('skill_id', $skill->id)->exists())->toBeFalse();
});
