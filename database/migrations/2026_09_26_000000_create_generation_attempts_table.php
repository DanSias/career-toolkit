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
        Schema::create('generation_attempts', function (Blueprint $table) {
            $table->id();

            // Polymorphic on purpose (see App\Models\GenerationAttempt
            // and AppServiceProvider::configureMorphMap()) — a JobPosting,
            // JobAnalysis, or JobMatch depending on generation_type.
            // Columns declared explicitly (not via $table->morphs())
            // so the one composite index below covers exactly the
            // "does this subject+stage already have work in flight"
            // access pattern without a redundant second index on just
            // (subject_type, subject_id).
            $table->string('subject_type');
            $table->unsignedBigInteger('subject_id');

            $table->string('generation_type');
            $table->string('status');

            $table->timestamp('queued_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();

            // Nullable diagnostic/version metadata only — the same
            // shape already proven safe by ProviderDiagnostics::
            // toLogContext(), never raw prompt/response/candidate
            // content. See this table's class-level docblock.
            $table->string('provider')->nullable();
            $table->string('model')->nullable();
            $table->string('prompt_version')->nullable();
            $table->string('schema_version')->nullable();
            $table->unsignedInteger('prompt_tokens')->nullable();
            $table->unsignedInteger('completion_tokens')->nullable();
            $table->unsignedInteger('total_tokens')->nullable();
            $table->string('finish_reason')->nullable();
            $table->string('failure_category')->nullable();
            $table->text('failure_message')->nullable();

            // No FK constraint: generation_type determines which of
            // JobAnalysis/JobMatch/ResumeVariant this points to, so no
            // single table could be the constraint's target. See
            // App\Models\GenerationAttempt::result().
            $table->unsignedBigInteger('result_id')->nullable();

            $table->timestamps();

            $table->index(
                ['subject_type', 'subject_id', 'generation_type', 'status'],
                'generation_attempts_active_lookup_index',
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('generation_attempts');
    }
};
