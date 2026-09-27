<?php

namespace Database\Factories;

use App\Enums\GenerationStatus;
use App\Enums\GenerationType;
use App\Models\GenerationAttempt;
use App\Models\JobPosting;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Defaults to a freshly queued job_analysis attempt against a
 * JobPosting subject — the most common case tests need — override
 * generation_type/subject/status for the other lifecycles.
 *
 * @extends Factory<GenerationAttempt>
 */
class GenerationAttemptFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            // getMorphClass(), not JobPosting::class directly, so this
            // always writes the enforced morph map's alias ('job_posting')
            // rather than a raw class name — see
            // AppServiceProvider::configureMorphMap().
            'subject_type' => (new JobPosting)->getMorphClass(),
            'subject_id' => JobPosting::factory(),
            'generation_type' => GenerationType::JobAnalysis,
            'status' => GenerationStatus::Queued,
            'queued_at' => now(),
        ];
    }
}
