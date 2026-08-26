<?php

namespace Tests\Feature\Communications;

use App\Jobs\SendCommunicationJob;
use App\Models\Communication;
use App\Models\EmailAccount;
use App\Models\User;
use App\Models\WhatsAppBusinessNumber;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * STEP 16 (composer confirmation) + STEP 25 (auditability) at the HTTP
 * layer — CommunicationServiceTest already covers the underlying
 * authorization/content logic directly; this file proves the routes and
 * the "do not silently send" confirmation checkbox are actually wired
 * up correctly end to end.
 */
class CommunicationControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_sending_email_without_ticking_the_confirmation_checkbox_is_rejected(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        EmailAccount::factory()->create(['user_id' => $user->id]);

        $this->actingAs($user)->post('/communications/send/email', [
            'recipient' => 'lead@example.test',
            'subject' => 'Hi',
            'body' => 'Hello',
        ])->assertSessionHasErrors('confirm');

        Queue::assertNothingPushed();
    }

    public function test_a_confirmed_email_send_redirects_to_the_communication_and_queues_the_job(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        EmailAccount::factory()->create(['user_id' => $user->id]);

        $response = $this->actingAs($user)->post('/communications/send/email', [
            'recipient' => 'lead@example.test',
            'subject' => 'Hi',
            'body' => 'Hello',
            'confirm' => '1',
        ]);

        $communication = Communication::firstOrFail();
        $response->assertRedirect(route('communications.show', $communication));
        Queue::assertPushed(SendCommunicationJob::class);
    }

    public function test_sending_whatsapp_without_ticking_the_confirmation_checkbox_is_rejected(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $number = WhatsAppBusinessNumber::factory()->create();

        $this->actingAs($user)->post('/communications/send/whatsapp', [
            'whatsapp_number_id' => $number->id,
            'recipient' => '+15550001111',
            'body' => 'Hello',
        ])->assertSessionHasErrors('confirm');

        Queue::assertNothingPushed();
    }

    public function test_a_user_cannot_view_a_communication_that_belongs_to_another_team(): void
    {
        $viewer = User::factory()->create();
        $owner = User::factory()->create();
        $communication = Communication::factory()->create(['user_id' => $owner->id, 'team_id' => null]);

        $this->actingAs($viewer)->get("/communications/{$communication->id}")->assertForbidden();
    }

    public function test_the_sender_can_view_their_own_communication(): void
    {
        $owner = User::factory()->create();
        $communication = Communication::factory()->create(['user_id' => $owner->id, 'team_id' => null]);

        $this->actingAs($owner)->get("/communications/{$communication->id}")->assertOk();
    }

    public function test_a_manager_can_view_any_communication(): void
    {
        $manager = User::factory()->manager()->create();
        $owner = User::factory()->create();
        $communication = Communication::factory()->create(['user_id' => $owner->id, 'team_id' => null]);

        $this->actingAs($manager)->get("/communications/{$communication->id}")->assertOk();
    }
}
