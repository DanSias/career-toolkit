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
        Schema::create('resume_variant_education_selections', function (Blueprint $table) {
            $table->id();

            $table->foreignId('resume_variant_id')->constrained()->cascadeOnDelete();

            // Restrictive, not cascading — same reasoning as
            // education_matches.education_id. Table name passed
            // explicitly: "education" is grammatically uncountable, so
            // Laravel's automatic inference does not pluralize it to
            // "educations".
            $table->foreignId('education_id')->constrained('educations')->restrictOnDelete();

            $table->unsignedInteger('display_order');

            $table->timestamps();

            $table->unique(['resume_variant_id', 'education_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('resume_variant_education_selections');
    }
};
