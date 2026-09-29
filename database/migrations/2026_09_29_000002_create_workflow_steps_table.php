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
        Schema::create('workflow_steps', function (Blueprint $table) {
            $table->id();

            $table->foreignId('workflow_run_id')->constrained()->cascadeOnDelete();

            // Plain string, not a backed enum — exactly one real value
            // ('inspect_application') exists today, and a shared
            // cross-workflow-type enum would mix unrelated vocabularies
            // before a second workflow type justifies the abstraction.
            // See docs/domain-model.md "WorkflowRun, WorkflowStep, and
            // AgentRun".
            $table->string('step_key');

            // Shared with workflow_runs.status (App\Enums\WorkflowStatus)
            // deliberately — a step's own state machine is structurally
            // identical to a run's, just at finer grain.
            $table->string('status');

            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->string('failure_message')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('workflow_steps');
    }
};
