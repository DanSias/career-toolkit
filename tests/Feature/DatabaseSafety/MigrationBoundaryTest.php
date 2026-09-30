<?php

use Tests\Support\MigrationScratchConnection as Scratch;

afterEach(fn () => Scratch::tearDown());

it('constructs the actual old schema immediately before a named migration', function (string $boundary, string $table, string $column) {
    Scratch::setUpBefore($boundary);
    expect(Scratch::db()->getSchemaBuilder()->hasColumn($table, $column))->toBeFalse()
        ->and(Scratch::db()->table('migrations')->where('migration', '>=', $boundary)->count())->toBe(0)
        ->and(Scratch::db()->getSchemaBuilder()->hasTable('discovery_runs'))->toBeFalse();
})->with([
    ['2026_09_09_000002_move_role_snapshot_fields_off_resume_variant_experience_bullets_table', 'resume_variant_experience_bullets', 'resume_variant_experience_role_id'],
    ['2026_09_09_000003_add_snapshot_fields_to_resume_variant_skill_selections_table', 'resume_variant_skill_selections', 'name'],
    ['2026_09_09_000006_add_career_profile_ownership_to_projects_table', 'projects', 'career_profile_id'],
]);
