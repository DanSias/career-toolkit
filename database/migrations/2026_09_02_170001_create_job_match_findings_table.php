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
        Schema::create('job_match_findings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('job_match_id')->constrained()->cascadeOnDelete();

            // A JobMatchFinding has no meaning without the specific
            // JobAnalysisFinding it addresses — cascades exactly like
            // job_match_id, not restricted like the CareerFact/Education
            // references below (those point at independently-editable
            // live canonical data; this points at another row already
            // inside this same immutable snapshot tree, addressed
            // indirectly via job_matches.job_analysis_id).
            $table->foreignId('job_analysis_finding_id')->constrained()->cascadeOnDelete();

            // String, not a database enum — see docs/domain-model.md.
            $table->string('coverage');
            $table->text('coverage_rationale')->nullable();

            $table->timestamps();

            // Exactly one JobMatchFinding per JobAnalysisFinding within
            // a given JobMatch run — enforced at the DB level as well as
            // by application validation, so completeness/uniqueness can
            // never be silently violated by any write path.
            $table->unique(['job_match_id', 'job_analysis_finding_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('job_match_findings');
    }
};
