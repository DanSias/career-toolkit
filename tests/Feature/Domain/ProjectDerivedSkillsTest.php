<?php

use App\Models\CareerFact;
use App\Models\CareerProfile;
use App\Models\Employer;
use App\Models\Project;
use App\Models\Role;
use App\Models\Skill;
use Illuminate\Support\Facades\DB;

function factForProject(Project $project, CareerProfile $profile): CareerFact
{
    return CareerFact::factory()->for($profile)->create([
        'attributable_type' => $project->getMorphClass(),
        'attributable_id' => $project->id,
    ]);
}

it('derives distinct skills from career facts attributed directly to the project', function () {
    $profile = CareerProfile::factory()->create();
    $project = Project::factory()
        ->for(Role::factory()->for(Employer::factory()->for($profile)))
        ->create();

    $laravel = Skill::factory()->for($profile)->create(['name' => 'Laravel']);
    $typescript = Skill::factory()->for($profile)->create(['name' => 'TypeScript']);
    $gitlab = Skill::factory()->for($profile)->create(['name' => 'GitLab']);

    $factA = factForProject($project, $profile);
    $factA->skills()->attach([$laravel->id, $typescript->id]);

    $factB = factForProject($project, $profile);
    $factB->skills()->attach($gitlab->id);

    $derived = $project->fresh(['careerFacts.skills'])->derivedSkills();

    expect($derived)->toHaveCount(3)
        ->and($derived->pluck('name')->sort()->values()->all())->toBe(['GitLab', 'Laravel', 'TypeScript']);
});

it('deduplicates a skill shared by multiple facts on the same project', function () {
    $profile = CareerProfile::factory()->create();
    $project = Project::factory()
        ->for(Role::factory()->for(Employer::factory()->for($profile)))
        ->create();
    $skill = Skill::factory()->for($profile)->create();

    $factA = factForProject($project, $profile);
    $factA->skills()->attach($skill->id);
    $factB = factForProject($project, $profile);
    $factB->skills()->attach($skill->id);

    $derived = $project->fresh(['careerFacts.skills'])->derivedSkills();

    expect($derived)->toHaveCount(1);
});

it('does not include skills from role-level facts', function () {
    $profile = CareerProfile::factory()->create();
    $role = Role::factory()->for(Employer::factory()->for($profile))->create();
    $project = Project::factory()->for($role)->create();
    $skill = Skill::factory()->for($profile)->create();

    $roleFact = CareerFact::factory()->for($profile)->create([
        'attributable_type' => $role->getMorphClass(),
        'attributable_id' => $role->id,
    ]);
    $roleFact->skills()->attach($skill->id);

    $derived = $project->fresh(['careerFacts.skills'])->derivedSkills();

    expect($derived)->toHaveCount(0);
});

it('never creates project_skill rows as a side effect of deriving skills', function () {
    $profile = CareerProfile::factory()->create();
    $project = Project::factory()
        ->for(Role::factory()->for(Employer::factory()->for($profile)))
        ->create();
    $skill = Skill::factory()->for($profile)->create();
    $fact = factForProject($project, $profile);
    $fact->skills()->attach($skill->id);

    $project->fresh(['careerFacts.skills'])->derivedSkills();

    expect(DB::table('project_skill')->count())->toBe(0);
});

it('returns an empty collection cleanly for a project with no skilled facts', function () {
    $project = Project::factory()->create();

    $derived = $project->fresh(['careerFacts.skills'])->derivedSkills();

    expect($derived)->toHaveCount(0);
});
