<?php

namespace Database\Factories;

use App\Enums\WorkflowStatus;
use App\Enums\WorkflowType;
use App\Models\Application;
use App\Models\WorkflowRun;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Defaults to a freshly pending application_inspection run — override
 * status/timestamps for other lifecycles.
 *
 * @extends Factory<WorkflowRun>
 */
class WorkflowRunFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'application_id' => Application::factory(),
            'workflow_type' => WorkflowType::ApplicationInspection,
            'status' => WorkflowStatus::Pending,
        ];
    }
}
