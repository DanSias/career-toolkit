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
        Schema::create('resume_variant_summary_evidence', function (Blueprint $table) {
            $table->id();

            $table->foreignId('resume_variant_id')->constrained()->cascadeOnDelete();

            // Same restrictive reasoning as resume_variant_bullet_citations
            // — every summary claim carries the same protective lineage
            // guarantee a bullet's citations do.
            $table->foreignId('career_fact_id')->constrained()->restrictOnDelete();

            $table->timestamps();

            $table->unique(['resume_variant_id', 'career_fact_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('resume_variant_summary_evidence');
    }
};
