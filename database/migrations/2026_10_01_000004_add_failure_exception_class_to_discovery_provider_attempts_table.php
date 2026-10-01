<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Additive diagnostic field. Unexpected exception messages are omitted
     * because they can contain credentials or payloads; structural context
     * (source location, phase and counters) is logged instead.
     */
    public function up(): void
    {
        Schema::table('discovery_provider_attempts', function (Blueprint $table) {
            $table->string('failure_exception_class')->nullable()->after('failure_message');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('discovery_provider_attempts', function (Blueprint $table) {
            $table->dropColumn('failure_exception_class');
        });
    }
};
