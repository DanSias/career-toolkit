<?php

namespace Database\Factories;

use App\Enums\WorkflowStatus;
use App\Models\WorkflowRun;
use App\Models\WorkflowStep;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WorkflowStep>
 */
class WorkflowStepFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workflow_run_id' => WorkflowRun::factory(),
            'step_key' => 'inspect_application',
            'status' => WorkflowStatus::Pending,
        ];
    }
}
