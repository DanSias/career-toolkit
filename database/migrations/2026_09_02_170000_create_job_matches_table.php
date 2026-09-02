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
        Schema::create('job_matches', function (Blueprint $table) {
            $table->id();

            // A JobMatch is meaningless without the JobAnalysis it
            // compares against, or the CareerProfile it compares for —
            // both cascade, mirroring how job_analyses cascades from
            // job_postings and everything else cascades directly from
            // career_profiles. See docs/domain-model.md "Deletion
            // behavior".
            $table->foreignId('job_analysis_id')->constrained()->cascadeOnDelete();
            $table->foreignId('career_profile_id')->constrained()->cascadeOnDelete();

            $table->string('schema_version');
            $table->string('prompt_version')->nullable();
            $table->string('generated_by')->nullable();
            $table->timestamp('generated_at');

            // The normalized candidate + job payload actually supplied
            // to the provider — frozen at generation time so a later
            // edit to a live CareerFact/Education/JobAnalysisFinding can
            // never retroactively change what this snapshot is
            // understood to have been based on. Answers "what went in";
            // raw_response answers "what came out". Never contains
            // credentials, HTTP headers, or raw Evidence/provenance
            // documents. See docs/job-match-generation.md.
            $table->json('input_snapshot');

            // Full validated structured response, kept only as an audit
            // trail — never queried structurally. Same convention as
            // job_analyses.raw_response.
            $table->json('raw_response')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('job_matches');
    }
};
