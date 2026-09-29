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
        Schema::create('applications', function (Blueprint $table) {
            $table->id();

            // Deliberately restrictive, not cascading — an Application
            // is operational tracking history, not a disposable
            // snapshot; a JobPosting with a live Application against it
            // cannot be deleted until that history is dealt with, the
            // same RESTRICT posture already used for cross-references
            // into live canonical data elsewhere in this schema. See
            // docs/domain-model.md "Application".
            $table->foreignId('job_posting_id')->constrained()->restrictOnDelete();

            // Real, mutable operational state — not a snapshot version.
            // Currently only ever 'draft'; see App\Enums\ApplicationStatus
            // and docs/domain-model.md "Application".
            $table->string('status');

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('applications');
    }
};
