<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One frozen Selected-Projects entry within a ResumeVariant — an
 * independent/personal Project (`projects.role_id IS NULL`) Selection
 * chose to feature alongside Experience, never a professional one. See
 * docs/domain-model.md "ResumeVariant" -> "Selected Projects".
 *
 * `name`/`technology_names`/`live_url`/`repository_url` are captured
 * here as plain, frozen values — never re-resolved from the live
 * Project/Skill on render — the same reproducibility guarantee
 * `ResumeVariantExperienceRole` already gives Experience. `project_id`
 * is lineage/audit only (restrictOnDelete), never read for display.
 *
 * `technology_names` is a frozen JSON array rather than a child table:
 * unlike Skills (a candidate-wide list independently grouped/queried
 * by GenerateResumeDocument) or bullets (which need per-row citation
 * lineage — see resume_variant_project_bullets), a project's technology
 * line is a short, order-preserving list of strings with no
 * cross-cutting concern of its own — it is never queried or grouped
 * independently of the one project it belongs to.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('resume_variant_projects', function (Blueprint $table) {
            $table->id();

            // Internal to this snapshot's own tree — cascades with it.
            $table->foreignId('resume_variant_id')->constrained()->cascadeOnDelete();

            // Lineage only — restrictive, mirrors role_id/skill_id/
            // education_id: a Project cited by an already-generated
            // resume must not be silently deletable out from under
            // that history.
            $table->foreignId('project_id')->constrained()->restrictOnDelete();

            $table->string('name');
            $table->json('technology_names');
            $table->string('live_url')->nullable();
            $table->string('repository_url')->nullable();

            // Selection's own relevance order — never chronology;
            // independent Projects carry no dates. See
            // docs/domain-model.md "ResumeVariant" -> "Selected Projects".
            $table->unsignedInteger('display_order');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('resume_variant_projects');
    }
};
