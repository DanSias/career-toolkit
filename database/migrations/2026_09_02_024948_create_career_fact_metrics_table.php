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
        Schema::create('career_fact_metrics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('career_fact_id')->unique()->constrained()->cascadeOnDelete();
            $table->decimal('value', 15, 2);
            // Open-ended by design (percent_reduction, usd, hours_per_month,
            // ...) — not an enum. See docs/domain-model.md.
            $table->string('unit');
            $table->string('comparator')->nullable();
            $table->text('scope_note')->nullable();
            $table->text('guardrail')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('career_fact_metrics');
    }
};
