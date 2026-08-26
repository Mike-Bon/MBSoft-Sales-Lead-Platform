<?php

namespace Tests\Feature\Ai;

use App\Models\EmailAccount;
use App\Models\User;
use App\Models\WhatsAppBusinessNumber;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * STEP 19/34: proves the assistant's draft actually lands on the real,
 * already-tested composer — not a second, parallel confirmation
 * mechanism. See resources/views/assistant/show.blade.php's "Review &
 * Send" links and CommunicationController::resolveContext().
 */
class DraftToComposerHandoffTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_drafted_emails_content_prefills_the_real_composer(): void
    {
        $user = User::factory()->create();
        EmailAccount::factory()->create(['user_id' => $user->id]);

        $response = $this->actingAs($user)->get('/communications/compose/email?'.http_build_query([
            'recipient' => 'jamie@example.test',
            'subject' => 'Following up',
            'body' => 'Hi Jamie, checking in on the proposal.',
        ]));

        $response->assertOk()
            ->assertSee('jamie@example.test', false)
            ->assertSee('Following up', false)
            ->assertSee('Hi Jamie, checking in on the proposal.', false);
    }

    public function test_a_drafted_whatsapp_messages_content_and_number_prefill_the_real_composer(): void
    {
        $user = User::factory()->create();
        $number = WhatsAppBusinessNumber::factory()->create(['team_id' => null]);

        $response = $this->actingAs($user)->get('/communications/compose/whatsapp?'.http_build_query([
            'recipient' => '+15550001111',
            'body' => 'Hi Jamie, checking in.',
            'whatsapp_number_id' => $number->id,
        ]));

        $response->assertOk()->assertSee('+15550001111', false)->assertSee('Hi Jamie, checking in.', false);
    }

    public function test_the_prefilled_composer_still_requires_the_confirm_checkbox_to_send(): void
    {
        $user = User::factory()->create();
        EmailAccount::factory()->create(['user_id' => $user->id]);

        // Simulates submitting the prefilled form without ticking
        // confirm — the draft-to-composer handoff introduces no
        // shortcut around Phase 6's human-confirmation requirement.
        $this->actingAs($user)->post('/communications/send/email', [
            'recipient' => 'jamie@example.test',
            'subject' => 'Following up',
            'body' => 'Hi Jamie.',
        ])->assertSessionHasErrors('confirm');

        $this->assertDatabaseCount('communications', 0);
    }
}
