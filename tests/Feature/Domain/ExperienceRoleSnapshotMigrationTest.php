<?php

use App\Models\ResumeVariant;
use App\Support\ResumeDocument\GenerateResumeDocument;
use Illuminate\Support\Facades\Artisan;
use Tests\Support\MigrationScratchConnection as Scratch;

/**
 * Proves the 2026_09_09_000002 migration (which moves employer_id/
 * role_id/display_title off resume_variant_experience_bullets and
 * onto the new resume_variant_experience_roles snapshot table) is
 * safe against a database that already has real, old-schema historical
 * data — not merely "not yet run against one." Runs the REAL migration
 * file (via Artisan, against an isolated scratch connection/file), not
 * a simulation of it, mirroring exactly how
 * App\Console\Commands\LiveEval\RunResumeVariantLiveEvaluation manages
 * its own separate connection. See that migration's own docblock for
 * why an earlier version of it was empirically proven unsafe (SQLite
 * does not roll back an already-executed ADD COLUMN just because a
 * later step in the same up() throws) before this version replaced it.
 */
afterEach(fn () => Scratch::tearDown());

it('reconstructs multiple bullets under one role into a single experience-role snapshot', function () {
    Scratch::setUpBefore('2026_09_09_000002_move_role_snapshot_fields_off_resume_variant_experience_bullets_table');
    ['profile_id' => $profileId, 'role_ids' => $roleIds] = Scratch::seedCandidate();
    $variantId = Scratch::seedResumeVariant($profileId);
    Scratch::seedBullet($variantId, 1, $roleIds['rocketgate'], 'Developer Support Engineer', 1, 'Bullet one.');
    Scratch::seedBullet($variantId, 1, $roleIds['rocketgate'], 'Developer Support Engineer', 2, 'Bullet two.');

    Artisan::call('migrate', ['--database' => Scratch::NAME, '--force' => true]);

    $db = Scratch::db();
    expect($db->table('resume_variant_experience_roles')->where('resume_variant_id', $variantId)->count())->toBe(1)
        ->and($db->table('resume_variant_experience_bullets')->where('resume_variant_id', $variantId)->pluck('resume_variant_experience_role_id')->unique())->toHaveCount(1);
});

it('keeps multiple roles within one variant distinct', function () {
    Scratch::setUpBefore('2026_09_09_000002_move_role_snapshot_fields_off_resume_variant_experience_bullets_table');
    ['profile_id' => $profileId, 'role_ids' => $roleIds] = Scratch::seedCandidate();
    $variantId = Scratch::seedResumeVariant($profileId);
    Scratch::seedBullet($variantId, 1, $roleIds['rocketgate'], 'Developer Support Engineer', 1, 'RocketGate bullet.');
    Scratch::seedBullet($variantId, 2, $roleIds['pearson'], 'Data & Analytics Lead Developer', 1, 'Pearson bullet.');

    Artisan::call('migrate', ['--database' => Scratch::NAME, '--force' => true]);

    $db = Scratch::db();
    $roles = $db->table('resume_variant_experience_roles')->where('resume_variant_id', $variantId)->get();
    expect($roles)->toHaveCount(2)
        ->and($roles->pluck('role_id')->sort()->values()->all())->toBe(collect($roleIds)->sort()->values()->all());
});

it('keeps two different ResumeVariants citing the same role in two separate snapshots', function () {
    Scratch::setUpBefore('2026_09_09_000002_move_role_snapshot_fields_off_resume_variant_experience_bullets_table');
    ['profile_id' => $profileId, 'role_ids' => $roleIds] = Scratch::seedCandidate();
    $variant1 = Scratch::seedResumeVariant($profileId, 'Variant 1');
    $variant2 = Scratch::seedResumeVariant($profileId, 'Variant 2');
    Scratch::seedBullet($variant1, 1, $roleIds['rocketgate'], 'Developer Support Engineer', 1, 'V1 bullet.');
    Scratch::seedBullet($variant2, 1, $roleIds['rocketgate'], 'Developer Support Engineer', 1, 'V2 bullet.');

    Artisan::call('migrate', ['--database' => Scratch::NAME, '--force' => true]);

    $db = Scratch::db();
    expect($db->table('resume_variant_experience_roles')->count())->toBe(2)
        ->and($db->table('resume_variant_experience_roles')->where('resume_variant_id', $variant1)->count())->toBe(1)
        ->and($db->table('resume_variant_experience_roles')->where('resume_variant_id', $variant2)->count())->toBe(1);
});

it('preserves each bullet\'s exact historical display_title on the new snapshot', function () {
    Scratch::setUpBefore('2026_09_09_000002_move_role_snapshot_fields_off_resume_variant_experience_bullets_table');
    ['profile_id' => $profileId, 'role_ids' => $roleIds] = Scratch::seedCandidate();
    $variantId = Scratch::seedResumeVariant($profileId);
    Scratch::seedBullet($variantId, 2, $roleIds['pearson'], 'Data & Analytics Lead Developer', 1, 'A bullet.');

    Artisan::call('migrate', ['--database' => Scratch::NAME, '--force' => true]);

    $role = Scratch::db()->table('resume_variant_experience_roles')->where('resume_variant_id', $variantId)->first();
    expect($role->display_title)->toBe('Data & Analytics Lead Developer')
        ->and($role->display_title)->not->toBe('Data & Analytics Lead Developer / Data Analyst');
});

it('preserves bullet display_order within a role exactly', function () {
    Scratch::setUpBefore('2026_09_09_000002_move_role_snapshot_fields_off_resume_variant_experience_bullets_table');
    ['profile_id' => $profileId, 'role_ids' => $roleIds] = Scratch::seedCandidate();
    $variantId = Scratch::seedResumeVariant($profileId);
    Scratch::seedBullet($variantId, 1, $roleIds['rocketgate'], 'Developer Support Engineer', 3, 'Third.');
    Scratch::seedBullet($variantId, 1, $roleIds['rocketgate'], 'Developer Support Engineer', 1, 'First.');
    Scratch::seedBullet($variantId, 1, $roleIds['rocketgate'], 'Developer Support Engineer', 2, 'Second.');

    Artisan::call('migrate', ['--database' => Scratch::NAME, '--force' => true]);

    $bullets = Scratch::db()->table('resume_variant_experience_bullets')
        ->where('resume_variant_id', $variantId)->orderBy('display_order')->pluck('text');
    expect($bullets->all())->toBe(['First.', 'Second.', 'Third.']);
});

it('ranks role snapshots deterministically, reverse-chronological, current role first', function () {
    Scratch::setUpBefore('2026_09_09_000002_move_role_snapshot_fields_off_resume_variant_experience_bullets_table');
    ['profile_id' => $profileId, 'role_ids' => $roleIds] = Scratch::seedCandidate();
    $variantId = Scratch::seedResumeVariant($profileId);
    // Insert Pearson (older, ended 2024) BEFORE RocketGate (current) to
    // prove ordering is computed from dates, never insertion order.
    Scratch::seedBullet($variantId, 2, $roleIds['pearson'], 'Data & Analytics Lead Developer', 1, 'Pearson bullet.');
    Scratch::seedBullet($variantId, 1, $roleIds['rocketgate'], 'Developer Support Engineer', 1, 'RocketGate bullet.');

    Artisan::call('migrate', ['--database' => Scratch::NAME, '--force' => true]);

    $roles = Scratch::db()->table('resume_variant_experience_roles')
        ->where('resume_variant_id', $variantId)->orderBy('display_order')->get();
    expect($roles[0]->employer_name)->toBe('RocketGate')
        ->and($roles[0]->end_year)->toBeNull()
        ->and($roles[1]->employer_name)->toBe('Pearson');
});

it('leaves no historical bullet orphaned — every bullet gets a non-null resume_variant_experience_role_id', function () {
    Scratch::setUpBefore('2026_09_09_000002_move_role_snapshot_fields_off_resume_variant_experience_bullets_table');
    ['profile_id' => $profileId, 'role_ids' => $roleIds] = Scratch::seedCandidate();
    $variantId = Scratch::seedResumeVariant($profileId);
    Scratch::seedBullet($variantId, 1, $roleIds['rocketgate'], 'Developer Support Engineer', 1, 'A.');
    Scratch::seedBullet($variantId, 2, $roleIds['pearson'], 'Data & Analytics Lead Developer', 1, 'B.');

    Artisan::call('migrate', ['--database' => Scratch::NAME, '--force' => true]);

    expect(Scratch::db()->table('resume_variant_experience_bullets')->whereNull('resume_variant_experience_role_id')->count())->toBe(0);
});

it('aborts rather than silently dropping data when a bullet cannot be reconstructed, and never destroys the old columns', function () {
    Scratch::setUpBefore('2026_09_09_000002_move_role_snapshot_fields_off_resume_variant_experience_bullets_table');
    ['profile_id' => $profileId, 'role_ids' => $roleIds] = Scratch::seedCandidate();
    $variantId = Scratch::seedResumeVariant($profileId);
    // A real role_id (the old schema's own FK already makes a
    // nonexistent role_id structurally impossible — see
    // ExperienceRoleSnapshotBackfiller's own docblock) but an empty
    // display_title, which the backfiller has nothing to preserve.
    Scratch::seedBullet($variantId, 1, $roleIds['rocketgate'], '', 1, 'Unreconstructable bullet.');

    expect(fn () => Artisan::call('migrate', ['--database' => Scratch::NAME, '--force' => true]))
        ->toThrow(RuntimeException::class, 'no display_title to preserve');

    $db = Scratch::db();
    // The migration must not be recorded as applied, and the original
    // employer_id/role_id/display_title columns and data must still
    // be fully present — nothing was dropped.
    expect($db->table('migrations')->where('migration', 'like', '2026_09_09_000002%')->exists())->toBeFalse();
    $bullet = $db->table('resume_variant_experience_bullets')->first();
    expect($bullet->role_id)->toBe($roleIds['rocketgate'])
        ->and($bullet->display_title)->toBe('')
        ->and($bullet->text)->toBe('Unreconstructable bullet.');
});

it('recovers cleanly on a retry after the unreconstructable data is fixed, with no leftover duplicate-column failure', function () {
    Scratch::setUpBefore('2026_09_09_000002_move_role_snapshot_fields_off_resume_variant_experience_bullets_table');
    ['profile_id' => $profileId, 'role_ids' => $roleIds] = Scratch::seedCandidate();
    $variantId = Scratch::seedResumeVariant($profileId);
    $badBulletId = Scratch::seedBullet($variantId, 1, $roleIds['rocketgate'], '', 1, 'Unreconstructable bullet.');

    expect(fn () => Artisan::call('migrate', ['--database' => Scratch::NAME, '--force' => true]))
        ->toThrow(RuntimeException::class);

    // Fix the bad row (supply the missing display_title), then retry.
    Scratch::db()->table('resume_variant_experience_bullets')->where('id', $badBulletId)->update(['display_title' => 'Developer Support Engineer']);
    Artisan::call('migrate', ['--database' => Scratch::NAME, '--force' => true]);

    $db = Scratch::db();
    expect($db->table('migrations')->where('migration', 'like', '2026_09_09_000002%')->exists())->toBeTrue()
        ->and($db->table('resume_variant_experience_bullets')->whereNull('resume_variant_experience_role_id')->count())->toBe(0);
});

it('renders identically, for every field that existed before migration, via the real GenerateResumeDocument pipeline', function () {
    Scratch::setUpBefore('2026_09_09_000002_move_role_snapshot_fields_off_resume_variant_experience_bullets_table');
    ['profile_id' => $profileId, 'role_ids' => $roleIds] = Scratch::seedCandidate();
    $variantId = Scratch::seedResumeVariant($profileId, 'A targeted historical summary.');
    Scratch::seedBullet($variantId, 1, $roleIds['rocketgate'], 'Developer Support Engineer', 1, 'Built things.');
    Scratch::seedBullet($variantId, 2, $roleIds['pearson'], 'Data & Analytics Lead Developer', 1, 'Analyzed things.');

    // Old-shape skill/education selections (no name/category/institution
    // etc. yet — those columns don't exist pre-migration) — exercises
    // migrations 000003/000004's own backfill too, exactly like real
    // historical data, rather than pre-filling post-migration columns
    // by hand.
    $skillId = Scratch::seedSkill($profileId, 'React', 'react', 'build_technology');
    Scratch::seedSkillSelection($variantId, $skillId, 1);
    $educationId = Scratch::seedEducation($profileId, 'Test University', 'Bachelor of Science', 'Computer Science', null, 2010);
    Scratch::seedEducationSelection($variantId, $educationId, 1);

    Artisan::call('migrate', ['--database' => Scratch::NAME, '--force' => true]);

    $previousDefault = config('database.default');
    config(['database.default' => Scratch::NAME]);

    try {
        $variant = ResumeVariant::findOrFail($variantId);
        $document = (new GenerateResumeDocument)->generate($variant);
    } finally {
        config(['database.default' => $previousDefault]);
    }

    expect($document->summary)->toBe('A targeted historical summary.')
        ->and($document->experience)->toHaveCount(2)
        ->and($document->experience[0]->employerName)->toBe('RocketGate')
        ->and($document->experience[0]->displayTitle)->toBe('Developer Support Engineer')
        ->and($document->experience[0]->bullets)->toBe(['Built things.'])
        ->and($document->experience[1]->employerName)->toBe('Pearson')
        ->and($document->experience[1]->displayTitle)->toBe('Data & Analytics Lead Developer')
        ->and($document->experience[1]->bullets)->toBe(['Analyzed things.'])
        ->and($document->skills)->toHaveCount(1)
        ->and($document->skills[0]->label)->toBe('Languages & Frameworks')
        ->and($document->skills[0]->skills)->toBe(['React'])
        ->and($document->education)->toHaveCount(1)
        ->and($document->education[0]->institution)->toBe('Test University')
        ->and($document->education[0]->dateRangeLabel)->toBe('2010');
});
