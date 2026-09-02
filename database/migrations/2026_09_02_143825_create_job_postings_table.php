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
        Schema::create('job_postings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('career_profile_id')->constrained()->cascadeOnDelete();
            $table->string('company');
            $table->string('title');
            $table->string('source_url')->nullable();
            $table->string('location')->nullable();
            // Verbatim captured job description — never rewritten after
            // intake. See docs/domain-model.md "JobPosting".
            $table->text('description');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('job_postings');
    }
};
