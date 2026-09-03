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
        Schema::create('resume_variant_skill_selections', function (Blueprint $table) {
            $table->id();

            $table->foreignId('resume_variant_id')->constrained()->cascadeOnDelete();

            // Restrictive, not cascading — same protective reasoning as
            // every other canonical-evidence reference in this snapshot.
            $table->foreignId('skill_id')->constrained()->restrictOnDelete();

            $table->unsignedInteger('display_order');

            $table->timestamps();

            $table->unique(['resume_variant_id', 'skill_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('resume_variant_skill_selections');
    }
};
