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
        Schema::create('resume_variant_experience_bullets', function (Blueprint $table) {
            $table->id();

            // Internal to this snapshot's own tree — cascades with it.
            $table->foreignId('resume_variant_id')->constrained()->cascadeOnDelete();

            // Deliberately restrictive, not cascading, mirroring
            // career_fact_matches.career_fact_id — a bullet's
            // Employer/Role/Project attribution is part of an immutable
            // historical result, not a cache of live data.
            $table->foreignId('employer_id')->constrained()->restrictOnDelete();
            $table->foreignId('role_id')->constrained()->restrictOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->restrictOnDelete();

            // The role display title Selection chose for THIS variant —
            // either the role's full canonical title or one of its
            // exact "/"-delimited segments (validated at generation
            // time; see ResumeSelectionResponseValidator). Duplicated
            // across every bullet under the same role within one
            // variant rather than living in a separate roles-within-
            // variant table: the UI must never derive it by reading
            // raw_response (audit-only, by established convention), and
            // a per-role table was deliberately avoided as
            // over-normalization for a value this small.
            $table->string('display_title');

            // Explicit, not array-position-dependent — this is a real
            // queryable/renderable row, not a JSON blob entry.
            $table->unsignedInteger('display_order');

            $table->text('text');

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('resume_variant_experience_bullets');
    }
};
