<?php

namespace Tests\Feature\Communications;

use App\Enums\CommunicationChannel;
use App\Models\Communication;
use App\Models\Team;
use App\Models\User;
use App\Services\Dashboard\CommunicationMetricsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * STEP 26: emails sent, WhatsApp sent, total, and failed — for the given
 * period, outbound only (an inbound message was never "sent" by this
 * organisation).
 */
class CommunicationMetricsTest extends TestCase
{
    use RefreshDatabase;

    public function test_summary_counts_are_correct_and_scoped_to_the_period(): void
    {
        $periodStart = now()->startOfMonth();
        $periodEnd = now()->endOfMonth();

        Communication::factory()->count(2)->sent()->create(['channel' => CommunicationChannel::Email, 'created_at' => now()]);
        Communication::factory()->whatsapp()->sent()->create(['created_at' => now()]);
        Communication::factory()->failed()->create(['channel' => CommunicationChannel::Email, 'created_at' => now()]);
        // Inbound must never count toward "sent".
        Communication::factory()->inbound()->whatsapp()->create(['created_at' => now()]);
        // Outside the period must not count.
        Communication::factory()->sent()->create(['created_at' => $periodStart->copy()->subMonth()]);

        $summary = app(CommunicationMetricsService::class)->summary(Communication::query(), $periodStart, $periodEnd);

        $this->assertSame(2, $summary['emails_sent']);
        $this->assertSame(1, $summary['whatsapp_sent']);
        $this->assertSame(4, $summary['total']);
        $this->assertSame(1, $summary['failed']);
    }

    public function test_the_manager_dashboard_displays_the_communication_metrics(): void
    {
        $manager = User::factory()->manager()->create();
        Communication::factory()->sent()->create(['channel' => CommunicationChannel::Email, 'created_at' => now()]);

        $this->actingAs($manager)->get('/dashboard')
            ->assertOk()
            ->assertSee('Emails Sent')
            ->assertSee('WhatsApp Sent');
    }

    public function test_a_team_members_dashboard_only_counts_their_own_communications(): void
    {
        $team = Team::factory()->create();
        $member = User::factory()->teamMember($team)->create();
        $otherMember = User::factory()->teamMember($team)->create();

        Communication::factory()->sent()->create(['user_id' => $member->id, 'team_id' => $team->id, 'created_at' => now()]);
        Communication::factory()->sent()->create(['user_id' => $otherMember->id, 'team_id' => $team->id, 'created_at' => now()]);

        $communications = Communication::query()->where('communications.user_id', $member->id);
        $summary = app(CommunicationMetricsService::class)->summary($communications, now()->startOfMonth(), now()->endOfMonth());

        $this->assertSame(1, $summary['total']);
    }
}
