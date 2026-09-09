<?php

use App\Support\ResumeVariant\SkillSelectionSnapshotBackfiller;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Freezes the Skill's own name/category onto the selection row at
 * generation time — a selected skill's rendered label must never
 * change because a live Skill was later renamed or recategorized. The
 * skill_id FK (restrictOnDelete) is kept unchanged for lineage/audit;
 * this only adds the presentation-ready snapshot alongside it. See
 * docs/resume-variant-generation.md "Historical reproducibility".
 *
 * **Historical-data-safe by construction**, following the exact
 * pattern proven necessary for the 2026_09_09_000002 migration: an
 * earlier version of this migration added `name`/`category` as a
 * single NOT-NULL step, which fails outright against any database
 * with pre-existing selection rows ("Cannot add a NOT NULL column
 * with default value NULL") — confirmed directly against the real
 * live-eval database, not merely suspected. This version instead:
 *
 * 1. Adds `name`/`category` as NULLABLE — never a constraint
 *    violation, regardless of existing rows.
 * 2. Backfills every existing row from the live Skill it still
 *    references via skill_id — see SkillSelectionSnapshotBackfiller,
 *    which aborts rather than inventing a value if a referenced Skill
 *    can't be resolved or has no name/category.
 * 3. Verifies no row remains incomplete — aborts (rolling back this
 *    migration under SQLite's transactional DDL for anything that
 *    hasn't already committed) rather than proceed with incomplete
 *    data.
 * 4. Only then makes `name`/`category` required.
 *
 * Idempotent: step 1 is guarded by `Schema::hasColumn()` so a retry
 * after a fixed failure doesn't fail on "duplicate column."
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('resume_variant_skill_selections', 'name')) {
            Schema::table('resume_variant_skill_selections', function (Blueprint $table) {
                $table->string('name')->nullable()->after('skill_id');
                $table->string('category')->nullable()->after('name');
            });
        }

        app(SkillSelectionSnapshotBackfiller::class)->run();

        $incompleteCount = DB::table('resume_variant_skill_selections')
            ->where(fn ($query) => $query->whereNull('name')->orWhereNull('category'))
            ->count();

        if ($incompleteCount > 0) {
            throw new RuntimeException(
                "Migration aborted: {$incompleteCount} skill selection(s) remain incomplete after backfill — refusing to finalize as required."
            );
        }

        Schema::table('resume_variant_skill_selections', function (Blueprint $table) {
            $table->string('name')->nullable(false)->change();
            $table->string('category')->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('resume_variant_skill_selections', function (Blueprint $table) {
            $table->dropColumn(['name', 'category']);
        });
    }
};
