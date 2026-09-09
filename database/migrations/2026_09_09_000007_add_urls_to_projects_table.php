<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Optional live-demo/repository URLs for a Project — structural
 * identity data (mirroring CareerProfile's own `portfolio_url`/
 * `github_url`), not an evidenced claim, so a plain nullable column is
 * appropriate rather than a CareerFact. Added for the three canonical
 * independent projects (Well Prompted, PromptWorks, Well Applied),
 * which document a live demo and a GitHub repository each — see
 * docs/canonical-data-proposal.md. Both nullable: no source establishes
 * either URL for most existing professional Projects, and none is
 * invented here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->string('live_url')->nullable()->after('default_visibility');
            $table->string('repository_url')->nullable()->after('live_url');
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn(['live_url', 'repository_url']);
        });
    }
};
