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
        Schema::create('resume_variant_target_term_usage_evidence', function (Blueprint $table) {
            $table->id();

            $table->foreignId('resume_variant_target_term_usage_id')
                ->constrained()
                ->cascadeOnDelete();

            // Restrictive — this pivot is the single source of truth
            // for what "actual technologies"/direct evidence back a
            // term usage; a qualified clause's real technology names are
            // *derived* from these citations' attached Skills at render
            // time, never a separately declared/validated string field.
            $table->foreignId('career_fact_id')->constrained()->restrictOnDelete();

            $table->timestamps();

            $table->unique(['resume_variant_target_term_usage_id', 'career_fact_id'], 'rvttu_evidence_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('resume_variant_target_term_usage_evidence');
    }
};
