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
        Schema::create('application_questions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('application_id')->constrained()->cascadeOnDelete();

            // Provenance — protect it, don't cascade it away. Nothing
            // currently deletes an AgentRun in isolation, but the FK
            // direction still reflects "this is history to protect,"
            // the same posture every provenance-into-history FK in this
            // schema already takes.
            $table->foreignId('agent_run_id')->constrained()->restrictOnDelete();

            // Explicit, required, zero-based source-form document
            // order — never inferred from id/insertion order. Real
            // domain information: what order a human filling out the
            // actual form would encounter these questions in. See
            // docs/domain-model.md "Application" ("ApplicationQuestion").
            $table->unsignedInteger('position');

            $table->string('external_field_id')->nullable();

            // Nullable — an honest "unresolved" is an acceptable
            // extraction outcome, never a forced guess. See
            // docs/application-inspector.md "ApplicationQuestion
            // ordering".
            $table->text('raw_label')->nullable();

            // App\Enums\ApplicationQuestionLabelSource.
            $table->string('label_source');

            // Plain string — open-ended per ATS, diagnostic/display data
            // only, not branched on yet.
            $table->string('control_type');

            // Nullable — required-ness can itself be undeterminable,
            // distinct from false.
            $table->boolean('required')->nullable();

            $table->json('options')->nullable();
            $table->string('section')->nullable();

            // App\Enums\ApplicationQuestionExtractionSource.
            $table->string('extraction_source');

            $table->timestamps();

            // Serves "list this Application's questions in source-form
            // order" — the concrete query this table exists to answer.
            $table->index(['application_id', 'position']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('application_questions');
    }
};
