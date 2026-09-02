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
        Schema::create('education_matches', function (Blueprint $table) {
            $table->id();

            $table->foreignId('job_match_finding_id')->constrained()->cascadeOnDelete();

            // Same reasoning as career_fact_matches.career_fact_id:
            // deliberately restrictive, not cascading — an Education row
            // referenced by a JobMatch snapshot must not be silently
            // deletable out from under that history. Education has no
            // dedicated deletion path of its own today beyond the
            // CareerProfile-level cascade (see docs/domain-model.md
            // "Deletion behavior"), so this restriction only ever
            // matters transitively through that cascade — exactly the
            // protection intended. See docs/job-match-generation.md.
            //
            // Table name passed explicitly: "education" is grammatically
            // uncountable, so Laravel's automatic column-to-table
            // inference (education_id -> "education") does not pluralize
            // it to the real table name, "educations".
            $table->foreignId('education_id')->constrained('educations')->restrictOnDelete();

            $table->string('relationship');
            $table->text('rationale')->nullable();

            $table->timestamps();

            $table->unique(['job_match_finding_id', 'education_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('education_matches');
    }
};
