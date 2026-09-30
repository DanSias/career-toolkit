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
     * Adds automatic-discovery support to JobPosting without changing
     * manual intake's existing behavior at all: `discovery_source`/
     * `status` default to 'manual'/'open' at the DB level as a
     * backstop, and App\Models\JobPosting's `creating` hook sets those
     * plus `discovered_at` in memory (see that hook's own docblock for
     * why the DB default alone isn't sufficient for an enum-cast
     * attribute) — so App\Http\Controllers\JobPostingController::
     * store() needs zero code changes. See
     * App\Enums\JobDiscoverySource's docblock for why discovery_source
     * and canonical_source are two separate nullable-independent
     * columns, never one overloaded field, and docs/job-discovery.md
     * "Discovery identity vs. canonical identity".
     */
    public function up(): void
    {
        Schema::table('job_postings', function (Blueprint $table) {
            // Discovery identity — where this posting was FOUND.
            // 'manual' for every hand-created row (the column
            // default); a real provider value for discovered rows.
            // discovery_source_id is the provider's own job ID, used
            // for (discovery_source, discovery_source_id) dedup tier 1
            // — see App\Support\JobDiscovery\IngestDiscoveredCandidate.
            $table->string('discovery_source')->default('manual');
            $table->string('discovery_source_id')->nullable();

            // Canonical identity — which ATS authoritatively OWNS this
            // posting, once resolved. Null until (and unless)
            // canonical enrichment succeeds; never populated for
            // manual postings. Deliberately independent from
            // discovery_source/discovery_source_id — see this
            // migration's class docblock.
            $table->string('canonical_source')->nullable();
            $table->string('canonical_source_id')->nullable();

            $table->string('remote_status')->nullable();
            $table->string('employment_type')->nullable();

            // Plain nullable integers/strings, not a JSON compensation
            // column — every real source spiked during investigation
            // returned these as flat fields, and flat columns are
            // directly filterable/sortable later without a JSON
            // extraction.
            $table->unsignedInteger('compensation_min')->nullable();
            $table->unsignedInteger('compensation_max')->nullable();
            $table->string('compensation_currency')->nullable();
            $table->string('compensation_interval')->nullable();

            $table->timestamp('posted_at')->nullable();

            // The PROVIDER's own "last updated" signal — deliberately
            // never overloaded onto Laravel's own `updated_at`, which
            // tracks when *this row* was last written, a different
            // fact. See App\Enums\JobDiscoverySource's docblock.
            $table->timestamp('source_updated_at')->nullable();

            // When Career Toolkit first saw this posting. Nullable at
            // the DB level only because SQLite's ALTER TABLE ADD
            // COLUMN rejects a NOT NULL column with no constant
            // default on a non-empty table — App\Models\JobPosting's
            // `creating` hook guarantees every row written through
            // Eloquent going forward gets a real value; existing rows
            // are backfilled to their created_at below.
            $table->timestamp('discovered_at')->nullable();

            $table->string('status')->default('open');

            // Null for manual postings (never rechecked); bumped on
            // every successful discovery recheck for discovered ones.
            $table->timestamp('last_checked_at')->nullable();

            // Small, structured, source-specific extras worth keeping
            // (Greenhouse's requisition_id/departments/offices,
            // Ashby's department/team, Himalayas' expiryDate/
            // locationRestrictions) — the same
            // known-useful-but-not-worth-over-normalizing shape
            // AgentRun.result_summary already establishes. Never a raw
            // full provider payload dump.
            $table->json('discovery_metadata')->nullable();

            // Dedup tier 1/2 (see App\Support\JobDiscovery\
            // IngestDiscoveredCandidate) — SQLite (and every major
            // engine) allows multiple NULLs in a unique index, so
            // manual/unenriched rows (both columns null) never
            // collide with each other or with real discovered/
            // canonical identities. Proven directly in
            // JobPostingDiscoveryIdentityTest.
            $table->unique(['discovery_source', 'discovery_source_id']);
            $table->unique(['canonical_source', 'canonical_source_id']);
        });

        // Backfill existing rows so every pre-existing JobPosting also
        // gets a real discovered_at — App\Models\JobPosting's
        // `creating` hook only covers rows created from here on.
        DB::table('job_postings')->whereNull('discovered_at')->update([
            'discovered_at' => DB::raw('created_at'),
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('job_postings', function (Blueprint $table) {
            $table->dropUnique(['discovery_source', 'discovery_source_id']);
            $table->dropUnique(['canonical_source', 'canonical_source_id']);

            $table->dropColumn([
                'discovery_source',
                'discovery_source_id',
                'canonical_source',
                'canonical_source_id',
                'remote_status',
                'employment_type',
                'compensation_min',
                'compensation_max',
                'compensation_currency',
                'compensation_interval',
                'posted_at',
                'source_updated_at',
                'discovered_at',
                'status',
                'last_checked_at',
                'discovery_metadata',
            ]);
        });
    }
};
