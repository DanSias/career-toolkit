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
        Schema::create('career_facts', function (Blueprint $table) {
            $table->id();

            // A CareerFact always belongs to a CareerProfile, regardless of
            // what it's attributed to below. This keeps "all facts for this
            // profile" a plain indexed column lookup rather than requiring a
            // polymorphic join.
            $table->foreignId('career_profile_id')->constrained()->cascadeOnDelete();

            // Stable, human-readable identifier for evidence citations and
            // future YAML/JSON exports — see docs/domain-model.md.
            $table->string('key')->unique();

            // String, not a database enum: see docs/domain-model.md.
            $table->string('fact_type');
            $table->text('statement');
            $table->string('verification');
            $table->string('visibility');
            $table->text('notes')->nullable();
            $table->unsignedInteger('sort_order')->default(0);

            // Attribution target: CareerProfile, Employer, Role, or Project.
            // No database-level foreign key is possible across a morph
            // column set; integrity is enforced at the model layer instead
            // — see docs/domain-model.md for the deletion-safety design.
            $table->string('attributable_type');
            $table->unsignedBigInteger('attributable_id');
            $table->index(['attributable_type', 'attributable_id']);

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('career_facts');
    }
};
