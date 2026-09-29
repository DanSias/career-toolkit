<?php

namespace Database\Factories;

use App\Enums\AgentRunStatus;
use App\Models\AgentRun;
use App\Models\WorkflowStep;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AgentRun>
 */
class AgentRunFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workflow_step_id' => WorkflowStep::factory(),
            'agent_type' => 'browser_inspector',
            'status' => AgentRunStatus::Queued,
        ];
    }
}
