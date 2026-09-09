<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One CareerFact cited as factual support for one generated
 * resume_variant_project_bullets row — mirrors
 * resume_variant_bullet_citations exactly, including the restricted
 * (never cascading) career_fact_id FK: this row is part of an
 * immutable historical result, not a cache of live data.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('resume_variant_project_bullet_citations', function (Blueprint $table) {
            $table->id();

            $table->foreignId('resume_variant_project_bullet_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignId('career_fact_id')->constrained()->restrictOnDelete();

            $table->timestamps();

            $table->unique(['resume_variant_project_bullet_id', 'career_fact_id'], 'rvpb_citations_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('resume_variant_project_bullet_citations');
    }
};
