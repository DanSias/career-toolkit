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
        Schema::create('workflow_runs', function (Blueprint $table) {
            $table->id();

            // A plain, required FK — not a polymorphic subject. There
            // is exactly one real WorkflowRun subject type today
            // (Application); GenerationAttempt earns its own polymorphic
            // subject because it has three real, simultaneous subject
            // types. See docs/domain-model.md "WorkflowRun, WorkflowStep,
            // and AgentRun".
            $table->foreignId('application_id')->constrained()->cascadeOnDelete();

            // Plain string, not a backed enum — exactly one real value
            // ('application_inspection') exists today. See
            // docs/domain-model.md "WorkflowRun, WorkflowStep, and
            // AgentRun".
            $table->string('workflow_type');

            $table->string('status');

            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->string('failure_message')->nullable();

            $table->timestamps();

            $table->index(['application_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('workflow_runs');
    }
};
