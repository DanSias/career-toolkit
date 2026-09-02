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
        Schema::create('job_analyses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('job_posting_id')->constrained()->cascadeOnDelete();
            $table->string('schema_version');
            $table->string('prompt_version')->nullable();
            $table->string('generated_by')->nullable();
            $table->timestamp('generated_at');
            // Full unprocessed model output, kept only as an audit trail
            // alongside the normalized findings below — never queried
            // structurally. See docs/domain-model.md "JobAnalysis".
            $table->json('raw_response')->nullable();
            $table->text('role_summary');
            // String, not a database enum: see docs/domain-model.md.
            $table->string('overall_seniority')->nullable();
            $table->text('seniority_rationale')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('job_analyses');
    }
};
