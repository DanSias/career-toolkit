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
        Schema::create('job_analysis_findings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('job_analysis_id')->constrained()->cascadeOnDelete();
            // String columns, not database enums, throughout — see
            // docs/domain-model.md.
            $table->string('category');
            $table->text('statement');
            $table->string('label')->nullable();
            $table->string('basis');
            $table->string('requirement_strength')->nullable();
            $table->string('emphasis')->nullable();
            $table->string('maturity')->nullable();
            // Faithful transcription of a stated number, not an
            // eligibility floor/ceiling assertion — see
            // docs/domain-model.md.
            $table->decimal('years_experience_min', 4, 1)->nullable();
            $table->decimal('years_experience_max', 4, 1)->nullable();
            $table->string('recency_requirement')->nullable();
            $table->string('time_horizon')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('job_analysis_findings');
    }
};
