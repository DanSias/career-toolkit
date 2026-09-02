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
        Schema::create('job_analysis_finding_evidence', function (Blueprint $table) {
            $table->id();
            $table->foreignId('job_analysis_finding_id')->constrained()->cascadeOnDelete();
            // Always a quote from the owning JobPosting's description —
            // deliberately no `source` column the way career_fact_evidence
            // has one; there is only ever one kind of source here. See
            // docs/domain-model.md "JobAnalysis".
            $table->text('excerpt');
            $table->string('source_section')->nullable();
            $table->string('source_locator')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('job_analysis_finding_evidence');
    }
};
