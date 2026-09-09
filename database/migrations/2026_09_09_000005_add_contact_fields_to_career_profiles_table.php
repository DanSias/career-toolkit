<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Resume-facing contact fields — deliberately separate from
 * users.email (the login credential). All nullable: no source
 * currently establishes phone or a precise location for this profile,
 * and none is invented here. Populated only via the canonical import
 * source (data/canonical-career-data.proposed.json's career_profile
 * object), never by ad-hoc seeding. See docs/canonical-data-proposal.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('career_profiles', function (Blueprint $table) {
            $table->string('email')->nullable()->after('name');
            $table->string('phone')->nullable()->after('email');
            $table->string('location')->nullable()->after('phone');
            $table->string('portfolio_url')->nullable()->after('location');
            $table->string('github_url')->nullable()->after('portfolio_url');
        });
    }

    public function down(): void
    {
        Schema::table('career_profiles', function (Blueprint $table) {
            $table->dropColumn(['email', 'phone', 'location', 'portfolio_url', 'github_url']);
        });
    }
};
