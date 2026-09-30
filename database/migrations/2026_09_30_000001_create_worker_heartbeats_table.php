<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * A small, purpose-built presence table — not a generalized worker
     * registry. Exactly one real worker type exists today
     * ('browser_inspector'), and identity is upserted (never
     * accumulated as a history) since only the most recent sighting of
     * each named worker matters. See App\Support\ApplicationInspection\
     * RecordWorkerHeartbeat and docs/application-inspector.md "Worker
     * presence".
     */
    public function up(): void
    {
        Schema::create('worker_heartbeats', function (Blueprint $table) {
            $table->id();

            // Matches AgentRun.worker_identity — the same value a
            // worker sends as worker_identity on every claim poll.
            $table->string('identity')->unique();

            // Matches AgentRun.agent_type's convention — a plain
            // string, not a backed enum, for the same reason: exactly
            // one real value ('browser_inspector') exists today.
            $table->string('worker_type');

            $table->timestamp('last_seen_at');

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('worker_heartbeats');
    }
};
