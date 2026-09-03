<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('resume_variants', function (Blueprint $table) {
            $table->id();

            // A first-class ownership path ("all resumes for this
            // candidate") independent of the match chain — same
            // justification as job_matches.career_profile_id.
            $table->foreignId('career_profile_id')->constrained()->cascadeOnDelete();

            // Deliberately restrictive, not cascading. Unlike JobAnalysis
            // -> JobMatch (where JobMatch is meaningless without its
            // JobAnalysis), a single JobMatch may inform several
            // ResumeVariant generations over time (full regenerations,
            // and future wording-only regenerations reusing the same
            // underlying match) — JobMatch is a protected, reusable
            // upstream resource here, the same relationship CareerFact
            // has to CareerFactMatch. You cannot delete the diagnostic
            // basis of a resume that was actually generated from it. See
            // docs/domain-model.md "ResumeVariant".
            $table->foreignId('job_match_id')->constrained()->restrictOnDelete();

            $table->string('schema_version');
            $table->string('selection_prompt_version');
            $table->string('wording_prompt_version');

            // Two separate calls, potentially independently retried in
            // the future — collapsing them into one field would lose
            // which stage actually answered.
            $table->string('selection_generated_by')->nullable();
            $table->string('wording_generated_by')->nullable();

            $table->timestamp('generated_at');

            // Frozen at generation time, exactly like job_matches'
            // input_snapshot/raw_response pair — a later edit to a live
            // CareerFact/Education/Skill must never retroactively change
            // what a persisted ResumeVariant is understood to have been
            // based on. selection_raw_response is also the exact,
            // reusable input for a future wording-only regeneration
            // (copy it verbatim into the new row, re-run only Stage 2).
            $table->json('selection_input_snapshot');
            $table->json('selection_raw_response')->nullable();
            $table->json('wording_input_snapshot');
            $table->json('wording_raw_response')->nullable();

            // The generated professional summary — a first-class column
            // since exactly one exists per variant, not a child table.
            $table->text('summary')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('resume_variants');
    }
};
