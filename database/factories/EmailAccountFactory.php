<?php

namespace Database\Factories;

use App\Enums\EmailAccountStatus;
use App\Models\EmailAccount;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EmailAccount>
 */
class EmailAccountFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'email_address' => fake()->unique()->safeEmail(),
            'access_token' => 'fake-access-token',
            'refresh_token' => 'fake-refresh-token',
            'token_expires_at' => now()->addHour(),
            'scopes' => 'https://www.googleapis.com/auth/gmail.send',
            'status' => EmailAccountStatus::Connected,
            'last_error' => null,
            'connected_at' => now(),
        ];
    }

    public function needsReauth(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => EmailAccountStatus::NeedsReauth,
        ]);
    }

    public function disconnected(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => EmailAccountStatus::Disconnected,
        ]);
    }
}
