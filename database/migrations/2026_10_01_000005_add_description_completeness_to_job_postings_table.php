<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Persists App\Enums\DescriptionCompleteness onto the DESCRIPTION
     * ACTUALLY STORED on a JobPosting — see App\Support\JobDiscovery\
     * IngestDiscoveredCandidate's resolveDescriptionCompleteness() for
     * the same precedence used going forward (canonical wins only when
     * its description text is what's actually stored, never merely
     * because a canonical match exists).
     *
     * Backfill for existing rows uses only deterministic provenance,
     * never description length/ellipsis heuristics:
     *
     *   - canonical_source IS NOT NULL: Unknown. At migration time no
     *     real row in this database has ever been canonically
     *     enriched (confirmed directly — 0 rows across every
     *     Automatic Job Discovery investigation and live run so far),
     *     so this branch is currently unreachable in practice; it
     *     exists only so a future canonicalized row from BEFORE this
     *     migration (none exist) is never guessed at rather than
     *     marked honestly unknown.
     *   - discovery_source = 'jobicy' or 'himalayas': Complete —
     *     both providers are confirmed-complete sources (see
     *     App\Support\JobDiscovery\Providers), and with no canonical
     *     override (previous bullet), the stored description is
     *     exactly what that provider supplied.
     *   - discovery_source = 'adzuna': Preview — confirmed
     *     snippet-by-design.
     *   - discovery_source = 'manual': Unknown — a hand-entered
     *     posting has no source-completeness provenance at all; its
     *     completeness genuinely cannot be established, so it is never
     *     guessed at as Complete merely because it exists.
     */
    public function up(): void
    {
        Schema::table('job_postings', function (Blueprint $table) {
            $table->string('description_completeness')->default('unknown')->after('description');
        });

        DB::table('job_postings')->whereNotNull('canonical_source')->update(['description_completeness' => 'unknown']);
        DB::table('job_postings')->whereNull('canonical_source')->where('discovery_source', 'jobicy')->update(['description_completeness' => 'complete']);
        DB::table('job_postings')->whereNull('canonical_source')->where('discovery_source', 'himalayas')->update(['description_completeness' => 'complete']);
        DB::table('job_postings')->whereNull('canonical_source')->where('discovery_source', 'adzuna')->update(['description_completeness' => 'preview']);
        DB::table('job_postings')->where('discovery_source', 'manual')->update(['description_completeness' => 'unknown']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('job_postings', function (Blueprint $table) {
            $table->dropColumn('description_completeness');
        });
    }
};
