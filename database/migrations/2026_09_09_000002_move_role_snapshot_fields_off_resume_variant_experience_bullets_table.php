<?php

use App\Support\ResumeVariant\ExperienceRoleSnapshotBackfiller;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Moves per-role snapshot data (employer_id/role_id lineage,
 * display_title) off every individual bullet row and onto the new
 * resume_variant_experience_roles table — a bullet now belongs to a
 * role snapshot instead of duplicating that role's identity on every
 * one of its own rows.
 *
 * **Historical-data-safe by construction**, not merely by having not
 * yet run against a populated database. An earlier version of this
 * migration dropped employer_id/role_id/display_title and added
 * resume_variant_experience_role_id as a single NOT-NULL column in
 * one step — verified directly (see the migration-safety regression
 * test and this class's own git history) that running that version
 * against a database with even one pre-existing bullet row fails
 * outright: SQLite rejects "ALTER TABLE ... ADD COLUMN ... NOT NULL"
 * with no default against a non-empty table
 * ("Cannot add a NOT NULL column with default value NULL"). This
 * version instead runs in four steps, each safe to run against
 * historical data:
 *
 * 1. Add resume_variant_experience_role_id as NULLABLE — a plain ADD
 *    COLUMN, never a constraint violation regardless of how many
 *    historical bullets already exist.
 * 2. Reconstruct one experience-role snapshot per (resume_variant_id,
 *    role_id) group of existing bullets and link every bullet to it —
 *    see ExperienceRoleSnapshotBackfiller for exactly what it
 *    preserves (each bullet's own historical display_title) versus
 *    what it can only read as of backfill time (employer_name and
 *    role dates — never historically snapshotted before this schema
 *    existed, so "current, at backfill time" is the honest choice,
 *    not a retroactive claim).
 * 3. Verify no bullet remains unlinked — abort (throwing, which rolls
 *    back the whole migration under SQLite's transactional DDL) rather
 *    than proceed with incomplete data.
 * 4. Only now — every row has a real value — drop the old columns
 *    (their data is fully preserved in the new table) and make
 *    resume_variant_experience_role_id required.
 *
 * Safe to run against an EMPTY table too (a fresh database, or the
 * main dev database before any ResumeVariant existed): the backfiller
 * simply finds nothing to do and step 4 changes a column with no
 * existing rows to violate its new NOT NULL constraint.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Guarded, not unconditional: if an earlier attempt at this
        // migration already added this column and then failed later
        // (e.g. the backfiller aborted on unreconstructable data —
        // verified directly that SQLite does not roll back the
        // already-executed ADD COLUMN just because a later step in
        // this same up() throws), a retry must not fail on "duplicate
        // column" — it should pick up exactly where the failed attempt
        // left off.
        if (! Schema::hasColumn('resume_variant_experience_bullets', 'resume_variant_experience_role_id')) {
            Schema::table('resume_variant_experience_bullets', function (Blueprint $table) {
                $table->foreignId('resume_variant_experience_role_id')
                    ->nullable()
                    ->after('resume_variant_id')
                    ->constrained('resume_variant_experience_roles')
                    ->cascadeOnDelete();
            });
        }

        app(ExperienceRoleSnapshotBackfiller::class)->run();

        // Defensive re-check at the actual point of no return, even
        // though the backfiller already throws on incomplete data —
        // this migration must never proceed to step 4 on anything
        // other than fully-linked data.
        $orphanCount = DB::table('resume_variant_experience_bullets')
            ->whereNull('resume_variant_experience_role_id')
            ->count();

        if ($orphanCount > 0) {
            throw new RuntimeException(
                "Migration aborted: {$orphanCount} historical bullet(s) remain unlinked after backfill — refusing to drop the old columns."
            );
        }

        Schema::table('resume_variant_experience_bullets', function (Blueprint $table) {
            $table->dropForeign(['employer_id']);
            $table->dropForeign(['role_id']);
            $table->dropColumn(['employer_id', 'role_id', 'display_title']);
            $table->foreignId('resume_variant_experience_role_id')->nullable(false)->change();
        });
    }

    /**
     * Best-effort but data-correct reversal: re-adds the old nullable
     * columns and backfills them from the snapshot table each
     * existing bullet still points to (via resume_variant_experience_role_id),
     * rather than merely restoring the empty column shape.
     */
    public function down(): void
    {
        Schema::table('resume_variant_experience_bullets', function (Blueprint $table) {
            $table->foreignId('employer_id')->nullable()->after('resume_variant_id')->constrained()->restrictOnDelete();
            $table->foreignId('role_id')->nullable()->after('employer_id')->constrained()->restrictOnDelete();
            $table->string('display_title')->nullable()->after('project_id');
        });

        // Per-row, not a single joined UPDATE: SQLite's UPDATE has no
        // portable way to SET columns from a joined table's values in
        // one statement via the query builder. Not a hot path (a
        // one-time reversal over however many historical bullets
        // exist), so simplicity wins over a cleverer single query.
        DB::table('resume_variant_experience_bullets')->orderBy('id')->each(function ($bullet) {
            $experienceRoleId = $bullet->resume_variant_experience_role_id;
            $roleId = DB::table('resume_variant_experience_roles')->where('id', $experienceRoleId)->value('role_id');
            $displayTitle = DB::table('resume_variant_experience_roles')->where('id', $experienceRoleId)->value('display_title');

            DB::table('resume_variant_experience_bullets')
                ->where('id', $bullet->id)
                ->update([
                    'role_id' => $roleId,
                    'employer_id' => DB::table('roles')->where('id', $roleId)->value('employer_id'),
                    'display_title' => $displayTitle,
                ]);
        });

        Schema::table('resume_variant_experience_bullets', function (Blueprint $table) {
            $table->dropForeign(['resume_variant_experience_role_id']);
            $table->dropColumn('resume_variant_experience_role_id');
            $table->foreignId('employer_id')->nullable(false)->change();
            $table->foreignId('role_id')->nullable(false)->change();
            $table->string('display_title')->nullable(false)->change();
        });
    }
};
