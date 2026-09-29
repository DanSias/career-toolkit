<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Enforces "at most one Application per JobPosting" at the
     * database level — the smallest correct mechanism for the actual
     * current invariant, not a partial/conditional index scoped to
     * status: App\Enums\ApplicationStatus has exactly one case
     * (Draft) today, so a plain unique constraint on job_posting_id
     * already expresses exactly what "reuse the existing Draft
     * Application, never create a second one" requires. Revisit if a
     * second ApplicationStatus case ever legitimately needs a second,
     * later Application against the same JobPosting (e.g. re-applying
     * after a prior one was rejected) — that is a real, separate
     * product decision, not something to anticipate here.
     */
    public function up(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->unique('job_posting_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->dropUnique(['job_posting_id']);
        });
    }
};
