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
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employer_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            // Month/year precision only — no source ever evidences a day
            // of month for employment dates. See docs/domain-model.md.
            $table->unsignedSmallInteger('start_year');
            $table->unsignedTinyInteger('start_month')->nullable();
            $table->unsignedSmallInteger('end_year')->nullable();
            $table->unsignedTinyInteger('end_month')->nullable();
            $table->text('summary')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('roles');
    }
};
