<?php

namespace Database\Factories;

use App\Enums\ApplicationQuestionExtractionSource;
use App\Enums\ApplicationQuestionLabelSource;
use App\Models\AgentRun;
use App\Models\Application;
use App\Models\ApplicationQuestion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ApplicationQuestion>
 */
class ApplicationQuestionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'application_id' => Application::factory(),
            'agent_run_id' => AgentRun::factory(),
            'position' => $this->faker->numberBetween(0, 30),
            'external_field_id' => $this->faker->slug(2),
            'raw_label' => $this->faker->sentence(3),
            'label_source' => ApplicationQuestionLabelSource::DomWrappingLabel,
            'control_type' => 'text',
            'required' => $this->faker->boolean(),
            'options' => null,
            'section' => null,
            'extraction_source' => ApplicationQuestionExtractionSource::Dom,
        ];
    }
}
