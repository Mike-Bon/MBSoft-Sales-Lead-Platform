<?php

namespace Database\Factories;

use App\Enums\CommunicationChannel;
use App\Enums\CommunicationDirection;
use App\Enums\CommunicationFailureCode;
use App\Enums\CommunicationStatus;
use App\Models\Communication;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Communication>
 */
class CommunicationFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'channel' => CommunicationChannel::Email,
            'direction' => CommunicationDirection::Outbound,
            'status' => CommunicationStatus::Queued,
            'provider' => 'gmail',
            'provider_message_id' => null,
            'provider_thread_id' => null,
            'email_account_id' => null,
            'whatsapp_number_id' => null,
            'template_id' => null,
            'user_id' => User::factory(),
            'team_id' => null,
            'organization_id' => null,
            'contact_id' => null,
            'lead_id' => null,
            'opportunity_id' => null,
            'subject' => fake()->sentence(4),
            'recipient' => fake()->safeEmail(),
            'sender' => fake()->safeEmail(),
            'body' => fake()->paragraph(),
            'sent_at' => null,
            'delivered_at' => null,
            'read_at' => null,
            'failed_at' => null,
            'failure_code' => null,
            'failure_reason' => null,
            'metadata' => null,
        ];
    }

    public function whatsapp(): static
    {
        return $this->state(fn (array $attributes) => [
            'channel' => CommunicationChannel::WhatsApp,
            'provider' => 'whatsapp_cloud_api',
            'subject' => null,
            'recipient' => '+1'.fake()->numerify('##########'),
            'sender' => '+1'.fake()->numerify('##########'),
        ]);
    }

    public function inbound(): static
    {
        return $this->state(fn (array $attributes) => [
            'direction' => CommunicationDirection::Inbound,
            'status' => CommunicationStatus::Delivered,
        ]);
    }

    public function sent(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => CommunicationStatus::Sent,
            'provider_message_id' => fake()->uuid(),
            'sent_at' => now(),
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => CommunicationStatus::Failed,
            'failure_code' => CommunicationFailureCode::ProviderError,
            'failure_reason' => 'The messaging provider reported an error',
            'failed_at' => now(),
        ]);
    }
}
