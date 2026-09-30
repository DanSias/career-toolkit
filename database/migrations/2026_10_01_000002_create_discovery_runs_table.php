<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Durable discovery execution history — deliberately NOT
     * WorkflowRun/WorkflowStep/AgentRun (that shape represents work
     * CLAIMED by an external worker over an HTTP poll protocol;
     * discovery runs entirely in-process inside Laravel, nothing ever
     * claims it) and NOT GenerationAttempt either (that shape is one
     * attempt against one subject producing one result; a discovery
     * run queries multiple providers and ingests many JobPostings).
     * The PATTERN is reused (durable queued/running/succeeded/failed
     * status with lifecycle timestamps and failure diagnostics), the
     * TABLE is not — see docs/job-discovery.md "Discovery execution
     * history".
     */
    public function up(): void
    {
        Schema::create('discovery_runs', function (Blueprint $table) {
            $table->id();

            $table->string('status');

            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('discovery_runs');
    }
};
