<?php

namespace Database\Factories;

use App\Enums\WorkflowScopeType;
use App\Enums\WorkflowStatus;
use App\Enums\WorkflowType;
use App\Models\User;
use App\Models\WorkflowExecution;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<WorkflowExecution>
 */
class WorkflowExecutionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $startedAt = now();

        return [
            'workflow' => WorkflowType::DailyFollowUpReview,
            'trigger' => 'scheduled',
            'status' => WorkflowStatus::Completed,
            'user_id' => User::factory(),
            'scope_type' => WorkflowScopeType::Individual,
            'scope_team_id' => null,
            'execution_key' => (string) Str::uuid(),
            'result' => 'No overdue follow-ups today.',
            'findings' => [],
            'agent_interaction_id' => null,
            'error_summary' => null,
            'started_at' => $startedAt,
            'completed_at' => $startedAt->copy()->addSeconds(3),
        ];
    }

    public function failed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => WorkflowStatus::Failed,
            'result' => null,
            'error_summary' => 'The workflow failed to complete.',
        ]);
    }
}
