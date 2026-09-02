<?php

use App\Enums\SkillCategory;
use App\Models\CareerFact;
use App\Models\CareerProfile;
use App\Models\Project;
use App\Models\Skill;
use Illuminate\Database\QueryException;

it('lets many career facts reference many skills', function () {
    $profile = CareerProfile::factory()->create();
    $laravel = Skill::factory()->for($profile)->create(['name' => 'Laravel', 'category' => SkillCategory::BuildTechnology]);
    $vue = Skill::factory()->for($profile)->create(['name' => 'Vue', 'category' => SkillCategory::BuildTechnology]);

    $factA = CareerFact::factory()->for($profile)->create();
    $factB = CareerFact::factory()->for($profile)->create();

    $factA->skills()->attach([$laravel->id, $vue->id]);
    $factB->skills()->attach($laravel->id);

    expect($factA->skills)->toHaveCount(2)
        ->and($factB->skills)->toHaveCount(1)
        ->and($laravel->careerFacts)->toHaveCount(2)
        ->and($vue->careerFacts)->toHaveCount(1);
});

it('lets a project reference skills directly', function () {
    $skill = Skill::factory()->create(['name' => 'GitLab', 'category' => SkillCategory::PlatformIntegration]);
    $project = Project::factory()->create();

    $project->skills()->attach($skill);

    expect($project->skills)->toHaveCount(1)
        ->and($skill->projects->first()->is($project))->toBeTrue();
});

it('classifies skills into distinct, non-flattened categories', function () {
    $profile = CareerProfile::factory()->create();

    $buildTech = Skill::factory()->for($profile)->create(['category' => SkillCategory::BuildTechnology]);
    $platform = Skill::factory()->for($profile)->create(['category' => SkillCategory::PlatformIntegration]);
    $capability = Skill::factory()->for($profile)->create(['category' => SkillCategory::Capability]);
    $practice = Skill::factory()->for($profile)->create(['category' => SkillCategory::Practice]);

    expect($buildTech->category)->toBe(SkillCategory::BuildTechnology)
        ->and($platform->category)->toBe(SkillCategory::PlatformIntegration)
        ->and($capability->category)->toBe(SkillCategory::Capability)
        ->and($practice->category)->toBe(SkillCategory::Practice);
});

it('prevents duplicate skill slugs within the same career profile', function () {
    $profile = CareerProfile::factory()->create();
    Skill::factory()->for($profile)->create(['slug' => 'gitlab']);

    expect(fn () => Skill::factory()->for($profile)->create(['slug' => 'gitlab']))
        ->toThrow(QueryException::class);
});
