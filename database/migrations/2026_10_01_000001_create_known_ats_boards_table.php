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
     * A small explicit company→ATS-board resolution/enrichment table —
     * deliberately NOT the future target-company-monitoring feature
     * (no watch cadence, no "companies I care about" concept here,
     * just "if a discovered candidate's company matches one of these,
     * here is where to canonically enrich it from"). See
     * docs/job-discovery.md "ATS board resolution".
     */
    public function up(): void
    {
        Schema::create('known_ats_boards', function (Blueprint $table) {
            $table->id();

            // Normalized (App\Support\JobDiscovery\Canonical\
            // NormalizeCompanyName) so a discovered candidate's raw
            // company string can be matched deterministically without
            // per-source casing/whitespace variance.
            $table->string('company_name')->unique();

            $table->string('ats_type');
            $table->string('board_identifier');

            $table->timestamps();
        });

        // Seed only enough REAL, live-verified mappings to exercise
        // all three canonical adapters — never invented identifiers.
        // Each was confirmed reachable and returning real job data
        // immediately before this migration was written (Anthropic:
        // 636 live Greenhouse postings; Velaura: 36 live Lever
        // postings; Ashby: 67 live Ashby postings on its own board).
        // Trivially extended later — see this table's own docblock.
        DB::table('known_ats_boards')->insert([
            ['company_name' => 'anthropic', 'ats_type' => 'greenhouse', 'board_identifier' => 'anthropic', 'created_at' => now(), 'updated_at' => now()],
            ['company_name' => 'velaura', 'ats_type' => 'lever', 'board_identifier' => 'velaura', 'created_at' => now(), 'updated_at' => now()],
            ['company_name' => 'ashby', 'ats_type' => 'ashby', 'board_identifier' => 'ashby', 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('known_ats_boards');
    }
};
