<?php

use App\Models\CareerProfile;
use App\Models\ResumeVariant;
use App\Models\Skill;
use App\Support\ResumeDocument\GenerateResumeDocument;

/**
 * Proves App\Support\ResumeDocument\GenerateResumeDocument's v2 Skills
 * presentation grouping — a deterministic, presentation-only
 * regrouping of already-selected skills into four fixed display
 * buckets (Languages & Frameworks, Data & Platforms, Engineering,
 * Marketing Technology), keyed off each skill's stable, canonical
 * Skill.slug rather than its mutable display Skill.name. Never touches
 * canonical Skill.category, ResumeVariantSkillSelection's own frozen
 * columns, or Selection — see GenerateResumeDocument's own class and
 * buildSkillGroups() docblocks.
 */
function skillPresentationVariant(): array
{
    $profile = CareerProfile::factory()->create();
    $variant = ResumeVariant::factory()->create(['career_profile_id' => $profile->id]);

    return [$profile, $variant];
}

function selectSkill(CareerProfile $profile, ResumeVariant $variant, string $name, string $slug, string $category, int $order): Skill
{
    $skill = Skill::factory()->create([
        'career_profile_id' => $profile->id,
        'name' => $name,
        'slug' => $slug,
        'category' => $category,
    ]);

    $variant->skillSelections()->create([
        'skill_id' => $skill->id,
        'name' => $name,
        'category' => $category,
        'display_order' => $order,
    ]);

    return $skill;
}

it('renames build_technology and platform_integration straight through to Languages & Frameworks / Data & Platforms', function () {
    [$profile, $variant] = skillPresentationVariant();

    selectSkill($profile, $variant, 'TypeScript', 'typescript', 'build_technology', 1);
    selectSkill($profile, $variant, 'BigQuery', 'bigquery', 'platform_integration', 2);

    $document = (new GenerateResumeDocument)->generate($variant->fresh(['skillSelections']));

    expect($document->skills)->toHaveCount(2)
        ->and($document->skills[0]->label)->toBe('Languages & Frameworks')
        ->and($document->skills[0]->skills)->toBe(['TypeScript'])
        ->and($document->skills[1]->label)->toBe('Data & Platforms')
        ->and($document->skills[1]->skills)->toBe(['BigQuery']);
});

it('defaults capability and practice skills to Engineering, and folds practice into Engineering rather than keeping it separate', function () {
    [$profile, $variant] = skillPresentationVariant();

    selectSkill($profile, $variant, 'AI-assisted development', 'ai-assisted-development', 'capability', 1);
    selectSkill($profile, $variant, 'Git', 'git', 'practice', 2);

    $document = (new GenerateResumeDocument)->generate($variant->fresh(['skillSelections']));

    expect($document->skills)->toHaveCount(1)
        ->and($document->skills[0]->label)->toBe('Engineering')
        ->and($document->skills[0]->skills)->toBe(['AI-assisted development', 'Git']);
});

it('routes a marketing-technology-slugged capability skill to Marketing Technology instead of Engineering', function () {
    [$profile, $variant] = skillPresentationVariant();

    selectSkill($profile, $variant, 'AI-assisted development', 'ai-assisted-development', 'capability', 1);
    selectSkill($profile, $variant, 'Lead generation', 'lead-generation', 'capability', 2);

    $document = (new GenerateResumeDocument)->generate($variant->fresh(['skillSelections']));

    expect($document->skills)->toHaveCount(2)
        ->and($document->skills[0]->label)->toBe('Engineering')
        ->and($document->skills[0]->skills)->toBe(['AI-assisted development'])
        ->and($document->skills[1]->label)->toBe('Marketing Technology')
        ->and($document->skills[1]->skills)->toBe(['Lead generation']);
});

it('routes an A/B testing practice skill to Marketing Technology despite its practice category — an explicit exception, not a blanket practice fold', function () {
    [$profile, $variant] = skillPresentationVariant();

    selectSkill($profile, $variant, 'A/B testing', 'ab-testing', 'practice', 1);
    selectSkill($profile, $variant, 'CI/CD', 'ci-cd', 'practice', 2);

    $document = (new GenerateResumeDocument)->generate($variant->fresh(['skillSelections']));

    expect($document->skills)->toHaveCount(2)
        ->and($document->skills[0]->label)->toBe('Engineering')
        ->and($document->skills[0]->skills)->toBe(['CI/CD'])
        ->and($document->skills[1]->label)->toBe('Marketing Technology')
        ->and($document->skills[1]->skills)->toBe(['A/B testing']);
});

it('defaults an unrecognized/future capability slug to Engineering rather than dropping it', function () {
    [$profile, $variant] = skillPresentationVariant();

    selectSkill($profile, $variant, 'Some Future Capability', 'some-future-capability', 'capability', 1);

    $document = (new GenerateResumeDocument)->generate($variant->fresh(['skillSelections']));

    expect($document->skills)->toHaveCount(1)
        ->and($document->skills[0]->label)->toBe('Engineering')
        ->and($document->skills[0]->skills)->toBe(['Some Future Capability']);
});

it('renders all four groups in fixed order and skips an empty group entirely, never rendering it blank', function () {
    [$profile, $variant] = skillPresentationVariant();

    selectSkill($profile, $variant, 'Marketing automation', 'marketing-automation', 'capability', 1);
    selectSkill($profile, $variant, 'TypeScript', 'typescript', 'build_technology', 2);

    $document = (new GenerateResumeDocument)->generate($variant->fresh(['skillSelections']));

    // Fixed display order (Languages & Frameworks -> Data & Platforms ->
    // Engineering -> Marketing Technology) regardless of selection/
    // display_order — Data & Platforms and Engineering both have zero
    // selected skills here and are skipped entirely, not rendered empty.
    expect($document->skills)->toHaveCount(2)
        ->and($document->skills[0]->label)->toBe('Languages & Frameworks')
        ->and($document->skills[1]->label)->toBe('Marketing Technology');
});

it('preserves each skill\'s selected display_order within its presentation group', function () {
    [$profile, $variant] = skillPresentationVariant();

    selectSkill($profile, $variant, 'Lead generation', 'lead-generation', 'capability', 2);
    selectSkill($profile, $variant, 'Campaign attribution', 'campaign-attribution', 'capability', 1);

    $document = (new GenerateResumeDocument)->generate($variant->fresh(['skillSelections']));

    expect($document->skills)->toHaveCount(1)
        ->and($document->skills[0]->skills)->toBe(['Campaign attribution', 'Lead generation']);
});

it('never loses or duplicates a selected skill across the four presentation groups', function () {
    [$profile, $variant] = skillPresentationVariant();

    $selected = [
        ['TypeScript', 'typescript', 'build_technology'],
        ['BigQuery', 'bigquery', 'platform_integration'],
        ['AI-assisted development', 'ai-assisted-development', 'capability'],
        ['Lead generation', 'lead-generation', 'capability'],
        ['Git', 'git', 'practice'],
        ['A/B testing', 'ab-testing', 'practice'],
    ];

    foreach ($selected as $i => [$name, $slug, $category]) {
        selectSkill($profile, $variant, $name, $slug, $category, $i + 1);
    }

    $document = (new GenerateResumeDocument)->generate($variant->fresh(['skillSelections']));

    $rendered = collect($document->skills)->flatMap(fn ($group) => $group->skills);

    expect($rendered->count())->toBe(count($selected))
        ->and($rendered->unique()->count())->toBe(count($selected))
        ->and($rendered->sort()->values()->all())->toBe(collect($selected)->pluck('0')->sort()->values()->all());
});

it('does not change canonical Skill.category or the frozen ResumeVariantSkillSelection.category column', function () {
    [$profile, $variant] = skillPresentationVariant();

    $skill = selectSkill($profile, $variant, 'Lead generation', 'lead-generation', 'capability', 1);

    (new GenerateResumeDocument)->generate($variant->fresh(['skillSelections']));

    expect($skill->fresh()->category->value)->toBe('capability')
        ->and($variant->fresh()->skillSelections->first()->category)->toBe('capability');
});
