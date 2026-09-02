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
        Schema::create('career_fact_matches', function (Blueprint $table) {
            $table->id();

            // Internal to this snapshot's own tree — cascades with it.
            $table->foreignId('job_match_finding_id')->constrained()->cascadeOnDelete();

            // Deliberately NOT cascadeOnDelete. A CareerFactMatch is
            // part of an immutable historical result, not a cache of
            // live data — deleting the CareerFact it references must
            // never silently destroy that history. CareerFact is
            // otherwise only ever deleted directly, or transitively via
            // CareerProfile cascade (see docs/domain-model.md "Deletion
            // behavior" — Employer/Role/Project deletion explicitly
            // reassigns CareerFacts rather than deleting them, so no
            // existing cascade path reaches here); restricting the
            // delete means "you cannot delete a CareerFact (or a
            // CareerProfile that owns one) that a JobMatch snapshot has
            // already cited as evidence" — a deliberate, desirable
            // consequence, not a conflict with existing behavior. See
            // docs/job-match-generation.md.
            $table->foreignId('career_fact_id')->constrained()->restrictOnDelete();

            // String, not a database enum — see docs/domain-model.md.
            $table->string('relationship');
            $table->text('rationale')->nullable();

            $table->timestamps();

            $table->unique(['job_match_finding_id', 'career_fact_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('career_fact_matches');
    }
};
