<?php

use App\Support\ResumeVariant\EducationSelectionSnapshotBackfiller;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Freezes the Education row's own institution/degree/field/dates onto
 * the selection row at generation time — the same reproducibility
 * guarantee as the skill-selection snapshot fields, for the same
 * reason. The education_id FK (restrictOnDelete) is kept unchanged for
 * lineage/audit; this only adds the presentation-ready snapshot
 * alongside it.
 *
 * **Historical-data-safe by construction** — same pattern as the
 * 2026_09_09_000002 and _000003 migrations: add nullable, backfill via
 * EducationSelectionSnapshotBackfiller (which never invents a missing
 * value), verify, then finalize `institution`/`degree` as required.
 * `field_of_study`/`start_year`/`end_year` stay nullable throughout —
 * they are genuinely nullable on Education itself (see
 * docs/domain-model.md "Education"), so a null backfilled value there
 * is real data, not incomplete data. Idempotent: step 1 is guarded by
 * `Schema::hasColumn()`.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('resume_variant_education_selections', 'institution')) {
            Schema::table('resume_variant_education_selections', function (Blueprint $table) {
                $table->string('institution')->nullable()->after('education_id');
                $table->string('degree')->nullable()->after('institution');
                $table->string('field_of_study')->nullable()->after('degree');
                $table->unsignedSmallInteger('start_year')->nullable()->after('field_of_study');
                $table->unsignedSmallInteger('end_year')->nullable()->after('start_year');
            });
        }

        app(EducationSelectionSnapshotBackfiller::class)->run();

        $incompleteCount = DB::table('resume_variant_education_selections')
            ->where(fn ($query) => $query->whereNull('institution')->orWhereNull('degree'))
            ->count();

        if ($incompleteCount > 0) {
            throw new RuntimeException(
                "Migration aborted: {$incompleteCount} education selection(s) remain incomplete after backfill — refusing to finalize as required."
            );
        }

        Schema::table('resume_variant_education_selections', function (Blueprint $table) {
            $table->string('institution')->nullable(false)->change();
            $table->string('degree')->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('resume_variant_education_selections', function (Blueprint $table) {
            $table->dropColumn(['institution', 'degree', 'field_of_study', 'start_year', 'end_year']);
        });
    }
};
