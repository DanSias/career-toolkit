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
        Schema::create('resume_variant_target_term_usages', function (Blueprint $table) {
            $table->id();

            $table->foreignId('resume_variant_id')->constrained()->cascadeOnDelete();

            // A term usage has no independent existence or reuse value
            // the way a canonical CareerFact does — it's a child of the
            // bullet it annotates, so this is the one deliberate cascade
            // in this table (unlike every canonical-evidence FK below,
            // which restricts). Null when `location` is `summary`.
            $table->foreignId('bullet_id')
                ->nullable()
                ->constrained('resume_variant_experience_bullets')
                ->cascadeOnDelete();

            // Restrictive — protects the historical reference to which
            // requirement this usage addressed.
            $table->foreignId('job_analysis_finding_id')->constrained()->restrictOnDelete();

            $table->string('target_term');

            // ResumeClaimPosture: direct | qualified | capability.
            // Plain string, not a DB enum — established convention.
            $table->string('posture');

            // ResumeTermUsageLocation: bullet | summary.
            $table->string('location');

            // ResumeQualifiedPhrase — always present (never null), using
            // the `not_applicable` sentinel when posture != qualified,
            // matching this codebase's established avoidance of
            // nullable+enum schema/column combinations.
            $table->string('relationship_phrase_key');

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('resume_variant_target_term_usages');
    }
};
