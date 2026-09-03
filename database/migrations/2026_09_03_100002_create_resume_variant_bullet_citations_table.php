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
        Schema::create('resume_variant_bullet_citations', function (Blueprint $table) {
            $table->id();

            $table->foreignId('resume_variant_experience_bullet_id')
                ->constrained()
                ->cascadeOnDelete();

            // Deliberately restrictive, not cascading — the FK
            // reference IS the evidence, exactly as in career_fact_matches.
            // A CareerFact cited by an already-generated resume must not
            // be silently deletable out from under that history.
            $table->foreignId('career_fact_id')->constrained()->restrictOnDelete();

            $table->timestamps();

            $table->unique(['resume_variant_experience_bullet_id', 'career_fact_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('resume_variant_bullet_citations');
    }
};
