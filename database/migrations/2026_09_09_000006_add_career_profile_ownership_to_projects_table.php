<?php

use App\Support\CareerData\ProjectCareerProfileBackfiller;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Project ownership model change (see docs/domain-model.md "Project
 * ownership"): every Project gains a direct, required
 * `career_profile_id`, and `role_id` becomes nullable. `role_id !==
 * null` still means a professional project owned by that Role;
 * `role_id === null` now means an independent/personal project owned
 * directly by the CareerProfile — one uniform ownership column for
 * both kinds, rather than an either/or (XOR) pair where professional
 * Projects would lack a direct `career_profile_id` of their own.
 *
 * **Historical-data-safe by construction**, following the exact
 * four-step pattern already proven necessary for a populated table in
 * this codebase (see the 2026_09_09_000002 migration's own docblock
 * for why: SQLite rejects "ALTER TABLE ... ADD COLUMN ... NOT NULL"
 * with no default against a non-empty table):
 *
 * 1. Add `career_profile_id` as NULLABLE — a plain ADD COLUMN, safe
 *    regardless of how many historical Projects already exist.
 * 2. Backfill every existing Project's `career_profile_id` from its
 *    `role_id -> roles.employer_id -> employers.career_profile_id`
 *    ownership path — see ProjectCareerProfileBackfiller.
 * 3. Verify every Project resolves to exactly one CareerProfile —
 *    abort (throwing, which rolls back the whole migration under
 *    SQLite's transactional DDL) rather than proceed with incomplete
 *    data.
 * 4. Only now — every row has a real value — make `career_profile_id`
 *    required, add its foreign key, and make `role_id` nullable.
 *
 * Safe to run against an EMPTY table too (a fresh database, or the
 * main dev database before any Project existed): the backfiller
 * simply finds nothing to do and step 4 changes a column with no
 * existing rows to violate its new NOT NULL constraint.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Guarded, not unconditional: if an earlier attempt at this
        // migration already added this column and then failed later
        // (the backfiller aborted on unreconstructable data —
        // SQLite does not roll back the already-executed ADD COLUMN
        // just because a later step in this same up() throws), a
        // retry must not fail on "duplicate column" — it should pick
        // up exactly where the failed attempt left off.
        if (! Schema::hasColumn('projects', 'career_profile_id')) {
            Schema::table('projects', function (Blueprint $table) {
                $table->foreignId('career_profile_id')
                    ->nullable()
                    ->after('id')
                    ->constrained()
                    ->cascadeOnDelete();
            });
        }

        app(ProjectCareerProfileBackfiller::class)->run();

        // Defensive re-check at the actual point of no return, even
        // though the backfiller already throws on incomplete data —
        // this migration must never proceed to step 4 on anything
        // other than fully-resolved data.
        $unresolvedCount = DB::table('projects')->whereNull('career_profile_id')->count();

        if ($unresolvedCount > 0) {
            throw new RuntimeException(
                "Migration aborted: {$unresolvedCount} project(s) remain without a resolved career_profile_id — refusing to finalize."
            );
        }

        Schema::table('projects', function (Blueprint $table) {
            $table->foreignId('career_profile_id')->nullable(false)->change();
            $table->foreignId('role_id')->nullable()->change();
        });
    }

    /**
     * Best-effort but data-correct reversal: professional projects
     * (role_id still set) simply drop the now-redundant
     * career_profile_id column. An independent project (role_id
     * already null) has no Role to fall back to under the old schema
     * at all — the old schema required role_id, so this can only
     * reverse cleanly if no independent Project exists yet; refuses
     * to silently invent a Role for one rather than corrupt data.
     */
    public function down(): void
    {
        $orphanedIndependentCount = DB::table('projects')->whereNull('role_id')->count();

        if ($orphanedIndependentCount > 0) {
            throw new RuntimeException(
                "Migration rollback aborted: {$orphanedIndependentCount} independent project(s) (role_id null) exist — ".
                'the pre-migration schema requires role_id on every Project and none can be safely invented.'
            );
        }

        Schema::table('projects', function (Blueprint $table) {
            $table->foreignId('role_id')->nullable(false)->change();
        });

        Schema::table('projects', function (Blueprint $table) {
            $table->dropForeign(['career_profile_id']);
            $table->dropColumn('career_profile_id');
        });
    }
};
