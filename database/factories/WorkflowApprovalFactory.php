<?php

namespace Database\Factories;

use App\Enums\ApprovalStatus;
use App\Enums\CommunicationChannel;
use App\Models\User;
use App\Models\WorkflowApproval;
use App\Models\WorkflowExecution;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WorkflowApproval>
 */
class WorkflowApprovalFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workflow_execution_id' => WorkflowExecution::factory(),
            'user_id' => User::factory(),
            'channel' => CommunicationChannel::Email,
            'recipient' => fake()->safeEmail(),
            'subject' => 'Following up',
            'body' => fake()->paragraph(),
            'organization_id' => null,
            'contact_id' => null,
            'lead_id' => null,
            'opportunity_id' => null,
            'whatsapp_number_id' => null,
            'status' => ApprovalStatus::Pending,
            'expires_at' => now()->addDays(3),
            'decided_at' => null,
            'decided_by' => null,
        ];
    }

    public function whatsapp(): static
    {
        return $this->state(fn (array $attributes) => [
            'channel' => CommunicationChannel::WhatsApp,
            'subject' => null,
            'recipient' => '+1'.fake()->numerify('##########'),
        ]);
    }

    public function expired(): static
    {
        return $this->state(fn (array $attributes) => [
            'expires_at' => now()->subDay(),
        ]);
    }

    public function approved(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ApprovalStatus::Approved,
            'decided_at' => now(),
        ]);
    }

    public function rejected(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ApprovalStatus::Rejected,
            'decided_at' => now(),
        ]);
    }
}
