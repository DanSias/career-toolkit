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
        Schema::create('agent_runs', function (Blueprint $table) {
            $table->id();

            $table->foreignId('workflow_step_id')->constrained()->cascadeOnDelete();

            // Plain string, not a backed enum — exactly one real value
            // ('browser_inspector') exists today. See
            // docs/domain-model.md "WorkflowRun, WorkflowStep, and
            // AgentRun".
            $table->string('agent_type');

            // App\Enums\AgentRunStatus — deliberately a distinct enum
            // class from App\Enums\GenerationStatus, even though the
            // four cases are the same shape (queued/running/succeeded/
            // failed). GenerationAttempt remains specialized; nothing
            // about AgentRun should reach into its enum.
            $table->string('status');

            // Which worker claimed this — real diagnostic/audit value
            // even with exactly one worker today. Populated by the
            // worker itself once claim/report endpoints exist (a later
            // implementation pass); this column is schema-only for now.
            $table->string('worker_identity')->nullable();

            $table->timestamp('started_at')->nullable();

            // The staleness mechanism for a worker that claims a run and
            // then crashes before reporting — set at claim time, checked
            // by a later implementation pass, never written to by this
            // one.
            $table->timestamp('claim_expires_at')->nullable();
            $table->timestamp('finished_at')->nullable();

            // Populated only when status = succeeded. Deliberately
            // orthogonal to status itself — a technically successful
            // execution that correctly stopped at an authentication wall
            // is status: succeeded, inspection_outcome:
            // authentication_required, never a failure. See
            // docs/domain-model.md "WorkflowRun, WorkflowStep, and
            // AgentRun".
            $table->string('inspection_outcome')->nullable();
            $table->text('inspection_stop_reason')->nullable();

            // Plain string, not a backed enum — v1 supports exactly one
            // ATS (Greenhouse); the moment a second is added is the
            // right moment to introduce a real enum.
            $table->string('ats_detected')->nullable();

            $table->string('failure_category')->nullable();
            $table->text('failure_message')->nullable();

            // Small, structured, non-content operational diagnostics
            // only (timing, warnings, counts) — the same
            // known-useful-but-not-worth-over-normalizing escape hatch
            // Evidence.metadata already establishes elsewhere in this
            // schema. Never a raw page/DOM/screenshot dump.
            $table->json('result_summary')->nullable();

            $table->timestamps();

            // Mirrors generation_attempts_active_lookup_index — "does
            // this WorkflowStep already have work in flight."
            $table->index(['workflow_step_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('agent_runs');
    }
};
