<?php

namespace Database\Factories;

use App\Enums\CommunicationChannel;
use App\Enums\RecordStatus;
use App\Models\MessageTemplate;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MessageTemplate>
 */
class MessageTemplateFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->words(3, true),
            'channel' => CommunicationChannel::Email,
            'subject' => 'Following up, {{first_name}}',
            'body' => "Hi {{first_name}},\n\nThis is {{salesperson_name}} from {{company_name}}.",
            'status' => RecordStatus::Active,
            'created_by' => User::factory(),
            'team_id' => null,
        ];
    }

    public function whatsapp(): static
    {
        return $this->state(fn (array $attributes) => [
            'channel' => CommunicationChannel::WhatsApp,
            'subject' => null,
            'body' => 'Hi {{first_name}}, this is {{salesperson_name}} from {{company_name}}.',
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => RecordStatus::Inactive,
        ]);
    }
}
