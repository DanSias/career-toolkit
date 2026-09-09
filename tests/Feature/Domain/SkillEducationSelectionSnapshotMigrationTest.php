<?php

use Illuminate\Support\Facades\Artisan;
use Tests\Support\MigrationScratchConnection as Scratch;

/**
 * Proves the 2026_09_09_000003 (skill selections) and _000004
 * (education selections) migrations are safe against a database that
 * already has real, old-schema historical selection rows — the same
 * class of bug found and fixed for the 2026_09_09_000002 migration
 * (see ExperienceRoleSnapshotMigrationTest and both migrations' own
 * docblocks): an earlier version of each added its snapshot columns
 * as a single NOT-NULL step, which fails outright against any
 * database with pre-existing rows, confirmed directly against the
 * real live-eval database. Runs the REAL migration files via Artisan
 * against an isolated scratch connection, never a simulation.
 */
afterEach(fn () => Scratch::tearDown());

// setUp(3) rolls back 000003/000004/000005, leaving 000001/000002
// already applied — bullets/roles are in their final shape, and
// resume_variant_skill_selections/resume_variant_education_selections
// are still old-schema (no name/category/institution/etc.), exactly
// isolating what these two migrations need to prove.
it('migrates populated skill selections successfully', function () {
    Scratch::setUp(8);
    ['profile_id' => $profileId] = Scratch::seedCandidate();
    $variantId = Scratch::seedResumeVariant($profileId);
    $skillId = Scratch::seedSkill($profileId, 'React', 'react', 'build_technology');
    Scratch::seedSkillSelection($variantId, $skillId, 1);

    Artisan::call('migrate', ['--database' => Scratch::NAME, '--force' => true]);

    $selection = Scratch::db()->table('resume_variant_skill_selections')->where('resume_variant_id', $variantId)->first();
    expect($selection->name)->toBe('React')
        ->and($selection->category)->toBe('build_technology');
});

it('migrates populated education selections successfully', function () {
    Scratch::setUp(8);
    ['profile_id' => $profileId] = Scratch::seedCandidate();
    $variantId = Scratch::seedResumeVariant($profileId);
    $educationId = Scratch::seedEducation($profileId, 'Test University', 'Bachelor of Science', 'Computer Science', 2006, 2010);
    Scratch::seedEducationSelection($variantId, $educationId, 1);

    Artisan::call('migrate', ['--database' => Scratch::NAME, '--force' => true]);

    $selection = Scratch::db()->table('resume_variant_education_selections')->where('resume_variant_id', $variantId)->first();
    expect($selection->institution)->toBe('Test University')
        ->and($selection->degree)->toBe('Bachelor of Science')
        ->and($selection->field_of_study)->toBe('Computer Science')
        ->and($selection->start_year)->toBe(2006)
        ->and($selection->end_year)->toBe(2010);
});

it('preserves a genuinely null field_of_study/start_year as real data, not incomplete data', function () {
    Scratch::setUp(8);
    ['profile_id' => $profileId] = Scratch::seedCandidate();
    $variantId = Scratch::seedResumeVariant($profileId);
    // No field_of_study, no start_year — a real, legitimate Education
    // shape (year-only degrees with no evidenced field are common —
    // see docs/domain-model.md "Education").
    $educationId = Scratch::seedEducation($profileId, 'Test University', 'Bachelor of Science', null, null, 2010);
    Scratch::seedEducationSelection($variantId, $educationId, 1);

    Artisan::call('migrate', ['--database' => Scratch::NAME, '--force' => true]);

    $selection = Scratch::db()->table('resume_variant_education_selections')->where('resume_variant_id', $variantId)->first();
    expect($selection->institution)->toBe('Test University')
        ->and($selection->field_of_study)->toBeNull()
        ->and($selection->start_year)->toBeNull()
        ->and($selection->end_year)->toBe(2010);
});

it('keeps multiple ResumeVariants\' skill/education selections isolated from each other', function () {
    Scratch::setUp(8);
    ['profile_id' => $profileId] = Scratch::seedCandidate();
    $variant1 = Scratch::seedResumeVariant($profileId, 'Variant 1');
    $variant2 = Scratch::seedResumeVariant($profileId, 'Variant 2');
    $reactId = Scratch::seedSkill($profileId, 'React', 'react', 'build_technology');
    $sqlId = Scratch::seedSkill($profileId, 'SQL', 'sql', 'capability');
    Scratch::seedSkillSelection($variant1, $reactId, 1);
    Scratch::seedSkillSelection($variant2, $sqlId, 1);

    Artisan::call('migrate', ['--database' => Scratch::NAME, '--force' => true]);

    $db = Scratch::db();
    expect($db->table('resume_variant_skill_selections')->where('resume_variant_id', $variant1)->value('name'))->toBe('React')
        ->and($db->table('resume_variant_skill_selections')->where('resume_variant_id', $variant2)->value('name'))->toBe('SQL');
});

it('preserves selection row counts and display_order exactly', function () {
    Scratch::setUp(8);
    ['profile_id' => $profileId] = Scratch::seedCandidate();
    $variantId = Scratch::seedResumeVariant($profileId);
    $skillIds = [
        Scratch::seedSkill($profileId, 'TypeScript', 'typescript', 'build_technology'),
        Scratch::seedSkill($profileId, 'React', 'react', 'build_technology'),
        Scratch::seedSkill($profileId, 'SQL', 'sql', 'capability'),
    ];
    foreach ($skillIds as $order => $skillId) {
        Scratch::seedSkillSelection($variantId, $skillId, $order + 1);
    }

    Artisan::call('migrate', ['--database' => Scratch::NAME, '--force' => true]);

    $selections = Scratch::db()->table('resume_variant_skill_selections')
        ->where('resume_variant_id', $variantId)->orderBy('display_order')->pluck('name');
    expect($selections->all())->toBe(['TypeScript', 'React', 'SQL']);
});

it('freezes values that exactly match the referenced Skill/Education at migration time', function () {
    Scratch::setUp(8);
    ['profile_id' => $profileId] = Scratch::seedCandidate();
    $variantId = Scratch::seedResumeVariant($profileId);
    $skillId = Scratch::seedSkill($profileId, 'Vue.js', 'vue-js', 'build_technology');
    Scratch::seedSkillSelection($variantId, $skillId, 1);
    $educationId = Scratch::seedEducation($profileId, 'Embry-Riddle Aeronautical University', 'Bachelor of Science', 'Engineering Physics', null, 2004);
    Scratch::seedEducationSelection($variantId, $educationId, 1);

    Artisan::call('migrate', ['--database' => Scratch::NAME, '--force' => true]);

    $db = Scratch::db();
    $liveSkill = $db->table('skills')->find($skillId);
    $selection = $db->table('resume_variant_skill_selections')->where('resume_variant_id', $variantId)->first();
    expect($selection->name)->toBe($liveSkill->name)
        ->and($selection->category)->toBe($liveSkill->category);

    $liveEducation = $db->table('educations')->find($educationId);
    $educationSelection = $db->table('resume_variant_education_selections')->where('resume_variant_id', $variantId)->first();
    expect($educationSelection->institution)->toBe($liveEducation->institution)
        ->and($educationSelection->degree)->toBe($liveEducation->degree)
        ->and($educationSelection->field_of_study)->toBe($liveEducation->field_of_study)
        ->and($educationSelection->end_year)->toBe($liveEducation->end_year);
});

it('leaves no skill or education selection orphaned/incomplete after migration', function () {
    Scratch::setUp(8);
    ['profile_id' => $profileId] = Scratch::seedCandidate();
    $variantId = Scratch::seedResumeVariant($profileId);
    $skillId = Scratch::seedSkill($profileId, 'React', 'react', 'build_technology');
    Scratch::seedSkillSelection($variantId, $skillId, 1);
    $educationId = Scratch::seedEducation($profileId, 'Test University', 'Bachelor of Science', null, null, 2010);
    Scratch::seedEducationSelection($variantId, $educationId, 1);

    Artisan::call('migrate', ['--database' => Scratch::NAME, '--force' => true]);

    $db = Scratch::db();
    expect($db->table('resume_variant_skill_selections')->where(fn ($q) => $q->whereNull('name')->orWhereNull('category'))->count())->toBe(0)
        ->and($db->table('resume_variant_education_selections')->where(fn ($q) => $q->whereNull('institution')->orWhereNull('degree'))->count())->toBe(0);
});

it('aborts the skill-selection migration rather than fabricating a value, and leaves pre-existing data intact', function () {
    Scratch::setUp(8);
    ['profile_id' => $profileId] = Scratch::seedCandidate();
    $variantId = Scratch::seedResumeVariant($profileId);
    // A skill_id that does not resolve to a live Skill — structurally
    // possible in the old schema (no restrictOnDelete existed to
    // prevent it before this migration adds any new constraint).
    $badSelectionId = Scratch::withoutForeignKeys(fn () => Scratch::seedSkillSelection($variantId, 999999, 1));

    expect(fn () => Artisan::call('migrate', ['--database' => Scratch::NAME, '--force' => true]))
        ->toThrow(RuntimeException::class, 'does not resolve to a live Skill');

    $db = Scratch::db();
    expect($db->table('migrations')->where('migration', 'like', '2026_09_09_000003%')->exists())->toBeFalse();
    $selection = $db->table('resume_variant_skill_selections')->where('id', $badSelectionId)->first();
    expect($selection->skill_id)->toBe(999999)
        ->and($selection->display_order)->toBe(1);
});

it('aborts the education-selection migration rather than fabricating a value, and leaves pre-existing data intact', function () {
    Scratch::setUp(8);
    ['profile_id' => $profileId] = Scratch::seedCandidate();
    $variantId = Scratch::seedResumeVariant($profileId);
    $badSelectionId = Scratch::withoutForeignKeys(fn () => Scratch::seedEducationSelection($variantId, 999999, 1));

    expect(fn () => Artisan::call('migrate', ['--database' => Scratch::NAME, '--force' => true]))
        ->toThrow(RuntimeException::class, 'does not resolve to a live Education');

    $db = Scratch::db();
    expect($db->table('migrations')->where('migration', 'like', '2026_09_09_000004%')->exists())->toBeFalse();
    $selection = $db->table('resume_variant_education_selections')->where('id', $badSelectionId)->first();
    expect($selection->education_id)->toBe(999999);
});

it('recovers cleanly on a retry after correcting the bad skill_id, with no leftover duplicate-column failure', function () {
    Scratch::setUp(8);
    ['profile_id' => $profileId] = Scratch::seedCandidate();
    $variantId = Scratch::seedResumeVariant($profileId);
    $badSelectionId = Scratch::withoutForeignKeys(fn () => Scratch::seedSkillSelection($variantId, 999999, 1));

    expect(fn () => Artisan::call('migrate', ['--database' => Scratch::NAME, '--force' => true]))
        ->toThrow(RuntimeException::class);

    $skillId = Scratch::seedSkill($profileId, 'React', 'react', 'build_technology');
    Scratch::db()->table('resume_variant_skill_selections')->where('id', $badSelectionId)->update(['skill_id' => $skillId]);
    Artisan::call('migrate', ['--database' => Scratch::NAME, '--force' => true]);

    $db = Scratch::db();
    expect($db->table('migrations')->where('migration', 'like', '2026_09_09_000003%')->exists())->toBeTrue()
        ->and($db->table('resume_variant_skill_selections')->where('id', $badSelectionId)->value('name'))->toBe('React');
});

it('recovers cleanly on a retry after correcting the bad education_id, with no leftover duplicate-column failure', function () {
    Scratch::setUp(8);
    ['profile_id' => $profileId] = Scratch::seedCandidate();
    $variantId = Scratch::seedResumeVariant($profileId);
    $badSelectionId = Scratch::withoutForeignKeys(fn () => Scratch::seedEducationSelection($variantId, 999999, 1));

    expect(fn () => Artisan::call('migrate', ['--database' => Scratch::NAME, '--force' => true]))
        ->toThrow(RuntimeException::class);

    $educationId = Scratch::seedEducation($profileId, 'Test University', 'Bachelor of Science', null, null, 2010);
    Scratch::db()->table('resume_variant_education_selections')->where('id', $badSelectionId)->update(['education_id' => $educationId]);
    Artisan::call('migrate', ['--database' => Scratch::NAME, '--force' => true]);

    $db = Scratch::db();
    expect($db->table('migrations')->where('migration', 'like', '2026_09_09_000004%')->exists())->toBeTrue()
        ->and($db->table('resume_variant_education_selections')->where('id', $badSelectionId)->value('institution'))->toBe('Test University');
});
