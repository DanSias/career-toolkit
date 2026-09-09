<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One frozen Role-level snapshot per Role appearing in a ResumeVariant
 * — introduced by the ATS Resume Renderer milestone, structural check
 * "Option B" (see docs/domain-model.md "ResumeVariant" -> "Experience
 * role snapshots"). A rendered resume's real shape is Role (with an
 * employer, a display title, and dates) containing an ordered set of
 * bullets — this table makes that a first-class row instead of an
 * emergent property of several bullets happening to repeat the same
 * employer/title/date values.
 *
 * Employer name and role dates are captured here as plain, frozen
 * values (never re-resolved from live Employer/Role data on render) —
 * the same reproducibility guarantee `display_title` already had, now
 * extended to the rest of what a rendered role header needs. See
 * docs/resume-variant-generation.md "Historical reproducibility".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('resume_variant_experience_roles', function (Blueprint $table) {
            $table->id();

            // Internal to this snapshot's own tree — cascades with it.
            $table->foreignId('resume_variant_id')->constrained()->cascadeOnDelete();

            // Lineage only — deliberately restrictive, not cascading,
            // mirroring every other canonical-evidence reference in
            // this subtree. Sufficient on its own to also protect
            // against Employer deletion: Employer -> Role deletion
            // cascades, and a cascade blocked partway by a downstream
            // RESTRICT fails the whole operation, so a separate direct
            // employer_id FK (as the bullets table used to carry) is
            // not needed for that protection.
            $table->foreignId('role_id')->constrained()->restrictOnDelete();

            // Frozen at generation time — never re-resolved from the
            // live Employer/Role on render. See class docblock.
            $table->string('employer_name');
            $table->string('display_title');
            $table->unsignedSmallInteger('start_year');
            $table->unsignedTinyInteger('start_month')->nullable();
            $table->unsignedSmallInteger('end_year')->nullable();
            $table->unsignedTinyInteger('end_month')->nullable();

            // Deterministic reverse-chronological rank computed once at
            // generation time (see GenerateResumeVariant) — never
            // re-derived from live Role dates on render, and never a
            // Selection-decided value.
            $table->unsignedInteger('display_order');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('resume_variant_experience_roles');
    }
};
