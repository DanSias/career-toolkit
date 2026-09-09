<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stage 2's generated bullet text for one resume_variant_projects row.
 * A real child table, not a JSON array on the parent, specifically so
 * each bullet keeps its own precise citation lineage (see
 * resume_variant_project_bullet_citations) — the same reasoning
 * resume_variant_experience_bullets already rests on. v1 persists
 * exactly one bullet per selected Project (see
 * docs/domain-model.md "ResumeVariant" -> "Selected Projects"), but the
 * table itself imposes no such limit — raising the per-project cap
 * later is a schema/prompt/validator change only, never a persistence
 * redesign or a loss of provenance.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('resume_variant_project_bullets', function (Blueprint $table) {
            $table->id();

            $table->foreignId('resume_variant_project_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('display_order');
            $table->text('text');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('resume_variant_project_bullets');
    }
};
