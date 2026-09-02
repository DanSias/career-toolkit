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
        Schema::create('career_fact_evidence', function (Blueprint $table) {
            $table->id();
            $table->foreignId('career_fact_id')->constrained()->cascadeOnDelete();

            // String, not a database enum: see docs/domain-model.md.
            $table->string('source');

            // Normal nullable provenance columns cover every source shape
            // encountered so far (resume, portfolio, repository,
            // user_confirmed) — see docs/domain-model.md for why these stay
            // as columns rather than an opaque JSON blob.
            $table->string('document')->nullable();
            $table->string('path')->nullable();
            $table->string('section')->nullable();
            $table->string('locator')->nullable();
            $table->text('quoted_text')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->text('note')->nullable();

            // Escape hatch for source-specific detail that doesn't warrant
            // its own column yet.
            $table->json('metadata')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('career_fact_evidence');
    }
};
