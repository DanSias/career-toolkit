<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * One row per provider per DiscoveryRun — see that table's
     * migration docblock. Counts here (not a per-candidate audit
     * table — see docs/job-discovery.md "Observability") are enough
     * to answer every question a discovery run's outcome needs to
     * answer without persisting every rejected candidate.
     */
    public function up(): void
    {
        Schema::create('discovery_provider_attempts', function (Blueprint $table) {
            $table->id();

            $table->foreignId('discovery_run_id')->constrained()->cascadeOnDelete();

            // App\Enums\JobDiscoverySource — always a real provider
            // value (himalayas/adzuna), never 'manual'.
            $table->string('provider');

            $table->string('status');

            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();

            $table->unsignedInteger('candidates_retrieved')->nullable();
            $table->unsignedInteger('candidates_accepted')->nullable();
            $table->unsignedInteger('jobs_created')->nullable();
            $table->unsignedInteger('jobs_updated')->nullable();
            $table->unsignedInteger('jobs_canonicalized')->nullable();

            $table->string('failure_category')->nullable();
            $table->text('failure_message')->nullable();

            $table->timestamps();

            $table->index(['discovery_run_id', 'provider']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('discovery_provider_attempts');
    }
};
