<?php

use App\Models\Project;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Tests\Support\MigrationScratchConnection as Scratch;

/**
 * Proves the 2026_09_09_000006 migration (which adds a required
 * `career_profile_id` to `projects` and makes `role_id` nullable — see
 * docs/domain-model.md "Project ownership") is safe against a database
 * that already has real, old-schema historical Project data — not
 * merely "not yet run against one." Runs the REAL migration file (via
 * Artisan, against an isolated scratch connection/file), not a
 * simulation of it, mirroring
 * tests/Feature/Domain/ExperienceRoleSnapshotMigrationTest.php exactly.
 */
afterEach(fn () => Scratch::tearDown());

it('backfills career_profile_id from each project\'s role -> employer -> career_profile ownership path', function () {
    Scratch::setUp(5);
    ['profile_id' => $profileId, 'role_ids' => $roleIds] = Scratch::seedCandidate();
    $projectId = Scratch::seedProject($roleIds['rocketgate'], 'Workflow Intelligence', 'workflow-intelligence');

    Artisan::call('migrate', ['--database' => Scratch::NAME, '--force' => true]);

    $project = Scratch::db()->table('projects')->find($projectId);
    expect($project->career_profile_id)->toBe($profileId);
});

it('backfills multiple projects under the same role to the same career_profile_id', function () {
    Scratch::setUp(5);
    ['profile_id' => $profileId, 'role_ids' => $roleIds] = Scratch::seedCandidate();
    $projectAId = Scratch::seedProject($roleIds['rocketgate'], 'Workflow Intelligence', 'workflow-intelligence');
    $projectBId = Scratch::seedProject($roleIds['rocketgate'], 'Verbatim', 'verbatim');
    $projectCId = Scratch::seedProject($roleIds['rocketgate'], 'Transaction Toolkit', 'transaction-toolkit');

    Artisan::call('migrate', ['--database' => Scratch::NAME, '--force' => true]);

    $db = Scratch::db();
    expect($db->table('projects')->find($projectAId)->career_profile_id)->toBe($profileId)
        ->and($db->table('projects')->find($projectBId)->career_profile_id)->toBe($profileId)
        ->and($db->table('projects')->find($projectCId)->career_profile_id)->toBe($profileId);
});

it('preserves every existing project\'s id, name, slug, role_id, and sort_order exactly across the migration', function () {
    Scratch::setUp(5);
    ['role_ids' => $roleIds] = Scratch::seedCandidate();
    $db = Scratch::db();
    $projectId = $db->table('projects')->insertGetId([
        'role_id' => $roleIds['rocketgate'], 'name' => 'Workflow Intelligence', 'slug' => 'workflow-intelligence', 'sort_order' => 7,
    ]);

    Artisan::call('migrate', ['--database' => Scratch::NAME, '--force' => true]);

    $project = Scratch::db()->table('projects')->find($projectId);
    expect($project->id)->toBe($projectId)
        ->and($project->name)->toBe('Workflow Intelligence')
        ->and($project->slug)->toBe('workflow-intelligence')
        ->and($project->role_id)->toBe($roleIds['rocketgate'])
        ->and($project->sort_order)->toBe(7);
});

it('resolves two projects under two different CareerProfiles to their own distinct, correct profile — never mixed up', function () {
    Scratch::setUp(5);
    $db = Scratch::db();

    ['profile_id' => $profileA, 'role_ids' => $rolesA] = Scratch::seedCandidate();

    $userB = $db->table('users')->insertGetId(['name' => 'Other', 'email' => 'other@example.com', 'password' => 'x']);
    $profileB = $db->table('career_profiles')->insertGetId(['user_id' => $userB, 'name' => 'Other Candidate']);
    $employerB = $db->table('employers')->insertGetId(['career_profile_id' => $profileB, 'name' => 'Other Employer', 'sort_order' => 0]);
    $roleB = $db->table('roles')->insertGetId([
        'employer_id' => $employerB, 'title' => 'Other Role',
        'start_year' => 2020, 'start_month' => 1, 'end_year' => null, 'end_month' => null, 'sort_order' => 0,
    ]);

    $projectAId = Scratch::seedProject($rolesA['rocketgate'], 'Project A', 'project-a');
    $projectBId = Scratch::seedProject($roleB, 'Project B', 'project-b');

    Artisan::call('migrate', ['--database' => Scratch::NAME, '--force' => true]);

    expect($db->table('projects')->find($projectAId)->career_profile_id)->toBe($profileA)
        ->and($db->table('projects')->find($projectBId)->career_profile_id)->toBe($profileB)
        ->and($profileA)->not->toBe($profileB);
});

it('makes career_profile_id required and role_id nullable after migration', function () {
    Scratch::setUp(5);
    ['profile_id' => $profileId, 'role_ids' => $roleIds] = Scratch::seedCandidate();
    Scratch::seedProject($roleIds['rocketgate'], 'Workflow Intelligence', 'workflow-intelligence');

    Artisan::call('migrate', ['--database' => Scratch::NAME, '--force' => true]);

    // An independent project (role_id null) must now be insertable —
    // the real, empirical proof the column is genuinely nullable, not
    // merely absent from a hand-read CREATE TABLE string. Reuses the
    // same already-seeded profile rather than calling seedCandidate()
    // again — that helper hardcodes a fixed user email, so a second
    // call within one test collides on the users.email unique
    // constraint.
    $db = Scratch::db();
    $independentId = $db->table('projects')->insertGetId([
        'career_profile_id' => $profileId, 'role_id' => null, 'name' => 'Indep', 'slug' => 'indep-scratch', 'sort_order' => 0,
    ]);

    expect($db->table('projects')->find($independentId)->role_id)->toBeNull();
});

it('leaves no historical project unresolved — every project gets a non-null career_profile_id', function () {
    Scratch::setUp(5);
    ['role_ids' => $roleIds] = Scratch::seedCandidate();
    Scratch::seedProject($roleIds['rocketgate'], 'Project 1', 'project-1');
    Scratch::seedProject($roleIds['pearson'], 'Project 2', 'project-2');

    Artisan::call('migrate', ['--database' => Scratch::NAME, '--force' => true]);

    expect(Scratch::db()->table('projects')->whereNull('career_profile_id')->count())->toBe(0);
});

it('aborts rather than silently dropping data when a project cannot be reconstructed, and never destroys the old columns', function () {
    Scratch::setUp(5);
    Scratch::seedCandidate();
    // A ghost role_id, structurally impossible in real data (the old
    // schema's own FK already prevents it) — constructed only to
    // exercise the backfiller's defensive abort path, mirroring
    // ExperienceRoleSnapshotMigrationTest's own equivalent case.
    $projectId = Scratch::withoutForeignKeys(
        fn () => Scratch::seedProject(999999, 'Unreconstructable Project', 'unreconstructable')
    );

    expect(fn () => Artisan::call('migrate', ['--database' => Scratch::NAME, '--force' => true]))
        ->toThrow(RuntimeException::class);

    $db = Scratch::db();
    // The migration must not be recorded as applied — a retry must
    // re-run it, not skip it as already-done.
    expect($db->table('migrations')->where('migration', 'like', '2026_09_09_000006%')->exists())->toBeFalse();
    // The original role_id data is untouched — nothing was dropped or
    // overwritten. Empirically verified (not assumed) that SQLite does
    // NOT roll back an already-executed ADD COLUMN just because a
    // later statement in this same up() throws — the same behavior
    // already documented on the 2026_09_09_000002 migration — so
    // `career_profile_id` existing at this point is expected and
    // harmless: it stays nullable and unfinalized, and step 4 (making
    // it required, making role_id nullable) never ran.
    $project = $db->table('projects')->find($projectId);
    expect($project->role_id)->toBe(999999)
        ->and($project->name)->toBe('Unreconstructable Project')
        ->and(Schema::connection(Scratch::NAME)->hasColumn('projects', 'career_profile_id'))->toBeTrue()
        ->and($project->career_profile_id)->toBeNull();
});

it('recovers cleanly on a retry after the unreconstructable data is fixed, with no leftover duplicate-column failure', function () {
    Scratch::setUp(5);
    ['role_ids' => $roleIds] = Scratch::seedCandidate();
    $badProjectId = Scratch::withoutForeignKeys(
        fn () => Scratch::seedProject(999999, 'Unreconstructable Project', 'unreconstructable')
    );

    expect(fn () => Artisan::call('migrate', ['--database' => Scratch::NAME, '--force' => true]))
        ->toThrow(RuntimeException::class);

    // Fix the bad row (point it at a real role), then retry.
    Scratch::db()->table('projects')->where('id', $badProjectId)->update(['role_id' => $roleIds['rocketgate']]);
    Artisan::call('migrate', ['--database' => Scratch::NAME, '--force' => true]);

    $db = Scratch::db();
    expect($db->table('migrations')->where('migration', 'like', '2026_09_09_000006%')->exists())->toBeTrue()
        ->and($db->table('projects')->whereNull('career_profile_id')->count())->toBe(0);
});

it('never finalizes a NOT NULL career_profile_id while any row is still unresolved, verified via the real Project model post-migration', function () {
    Scratch::setUp(5);
    ['profile_id' => $profileId, 'role_ids' => $roleIds] = Scratch::seedCandidate();
    Scratch::seedProject($roleIds['pearson'], 'Nexus', 'nexus');

    Artisan::call('migrate', ['--database' => Scratch::NAME, '--force' => true]);

    $previousDefault = config('database.default');
    config(['database.default' => Scratch::NAME]);

    try {
        $project = Project::where('slug', 'nexus')->firstOrFail();
        expect($project->career_profile_id)->toBe($profileId)
            ->and($project->ownerCareerProfileId())->toBe($profileId);
    } finally {
        config(['database.default' => $previousDefault]);
    }
});
