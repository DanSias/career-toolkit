<?php

namespace Database\Factories;

use App\Enums\EvidenceSource;
use App\Models\CareerFact;
use App\Models\Evidence;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Evidence>
 */
class EvidenceFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'career_fact_id' => CareerFact::factory(),
            'source' => EvidenceSource::UserConfirmed,
            'document' => null,
            'path' => null,
            'section' => null,
            'locator' => null,
            'quoted_text' => null,
            'confirmed_at' => now(),
            'note' => fake()->sentence(),
            'metadata' => null,
        ];
    }

    public function resume(): static
    {
        return $this->state(fn () => [
            'source' => EvidenceSource::Resume,
            'document' => 'sources/resume/Daniel_Sias_Resume.pdf',
            'section' => fake()->jobTitle(),
            'locator' => 'bullet 1',
            'confirmed_at' => null,
            'note' => null,
        ]);
    }

    public function portfolio(): static
    {
        return $this->state(fn () => [
            'source' => EvidenceSource::Portfolio,
            'path' => '../danielsias-dev/src/app/projects/example/page.tsx',
            'locator' => 'Results',
            'confirmed_at' => null,
            'note' => null,
        ]);
    }

    public function repository(): static
    {
        return $this->state(fn () => [
            'source' => EvidenceSource::Repository,
            'path' => '../example-repo/README.md',
            'locator' => 'Architecture',
            'confirmed_at' => null,
            'note' => null,
        ]);
    }
}
