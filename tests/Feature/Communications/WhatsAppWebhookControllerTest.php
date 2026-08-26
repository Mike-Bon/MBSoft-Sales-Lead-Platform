<?php

namespace Tests\Feature\Communications;

use App\Enums\CommunicationStatus;
use App\Models\Communication;
use App\Models\Contact;
use App\Models\User;
use App\Models\WhatsAppBusinessNumber;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * STEP 13/14: the webhook is unauthenticated by session (Meta calls it
 * directly), so every test here hits the route with no actingAs() and
 * asserts on signature/idempotency/matching behaviour instead.
 */
class WhatsAppWebhookControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.whatsapp.app_secret' => 'test-app-secret',
            'services.whatsapp.webhook_verify_token' => 'test-verify-token',
        ]);
    }

    public function test_the_verify_handshake_succeeds_with_the_correct_token(): void
    {
        $this->get('/webhooks/whatsapp?hub_mode=subscribe&hub_verify_token=test-verify-token&hub_challenge=echo-me')
            ->assertOk()
            ->assertSee('echo-me', false);
    }

    public function test_the_verify_handshake_fails_with_the_wrong_token(): void
    {
        $this->get('/webhooks/whatsapp?hub_mode=subscribe&hub_verify_token=wrong&hub_challenge=echo-me')
            ->assertStatus(403);
    }

    public function test_a_post_without_a_valid_signature_is_rejected(): void
    {
        $number = WhatsAppBusinessNumber::factory()->create(['phone_number_id' => '999888777']);

        $payload = $this->inboundPayload($number->phone_number_id, 'wamid.NO_SIG', '+15550009999', 'Hello');

        $this->postJson('/webhooks/whatsapp', $payload, ['X-Hub-Signature-256' => 'sha256=deadbeef'])
            ->assertStatus(403);

        $this->assertDatabaseCount('communications', 0);
    }

    public function test_a_correctly_signed_inbound_message_is_recorded_and_matched_to_a_contact(): void
    {
        $owner = User::factory()->create();
        $number = WhatsAppBusinessNumber::factory()->create(['phone_number_id' => '999888777']);
        $contact = Contact::factory()->create(['owner_id' => $owner->id, 'mobile' => '+1 (555) 000-9999']);

        $payload = $this->inboundPayload($number->phone_number_id, 'wamid.ABC1', '15550009999', 'Hello there');

        $this->postSigned($payload)->assertOk();

        $communication = Communication::first();
        $this->assertNotNull($communication);
        $this->assertSame('wamid.ABC1', $communication->provider_message_id);
        $this->assertSame(CommunicationStatus::Delivered, $communication->status);
        $this->assertTrue($communication->contact->is($contact));
        $this->assertSame($owner->id, $communication->user_id);
        $this->assertSame('Hello there', $communication->body);
    }

    public function test_an_unmatched_sender_is_still_recorded_not_discarded(): void
    {
        $number = WhatsAppBusinessNumber::factory()->create(['phone_number_id' => '999888777']);

        $payload = $this->inboundPayload($number->phone_number_id, 'wamid.UNMATCHED', '+15559998888', 'Who is this from?');

        $this->postSigned($payload)->assertOk();

        $communication = Communication::first();
        $this->assertNotNull($communication);
        $this->assertNull($communication->contact_id);
        $this->assertNull($communication->user_id);
    }

    public function test_the_same_inbound_message_id_is_never_recorded_twice(): void
    {
        $number = WhatsAppBusinessNumber::factory()->create(['phone_number_id' => '999888777']);
        $payload = $this->inboundPayload($number->phone_number_id, 'wamid.DUPLICATE', '+15550001111', 'Hi');

        $this->postSigned($payload)->assertOk();
        $this->postSigned($payload)->assertOk();

        $this->assertDatabaseCount('communications', 1);
    }

    public function test_a_message_for_an_unregistered_phone_number_id_is_ignored_without_erroring(): void
    {
        $payload = $this->inboundPayload('not-a-registered-id', 'wamid.X', '+15550001111', 'Hi');

        $this->postSigned($payload)->assertOk();

        $this->assertDatabaseCount('communications', 0);
    }

    public function test_a_status_callback_updates_the_matching_outbound_communication(): void
    {
        $number = WhatsAppBusinessNumber::factory()->create(['phone_number_id' => '999888777']);
        $communication = Communication::factory()->whatsapp()->sent()->create([
            'whatsapp_number_id' => $number->id,
            'provider_message_id' => 'wamid.OUT1',
        ]);

        $payload = [
            'entry' => [[
                'changes' => [[
                    'value' => [
                        'metadata' => ['phone_number_id' => $number->phone_number_id],
                        'statuses' => [[
                            'id' => 'wamid.OUT1',
                            'status' => 'delivered',
                        ]],
                    ],
                ]],
            ]],
        ];

        $this->postSigned($payload)->assertOk();

        $communication->refresh();
        $this->assertSame(CommunicationStatus::Delivered, $communication->status);
        $this->assertNotNull($communication->delivered_at);
    }

    public function test_a_status_callback_never_downgrades_an_already_read_message(): void
    {
        $number = WhatsAppBusinessNumber::factory()->create(['phone_number_id' => '999888777']);
        $communication = Communication::factory()->whatsapp()->create([
            'whatsapp_number_id' => $number->id,
            'provider_message_id' => 'wamid.OUT2',
            'status' => CommunicationStatus::Read,
        ]);

        $payload = [
            'entry' => [[
                'changes' => [[
                    'value' => [
                        'metadata' => ['phone_number_id' => $number->phone_number_id],
                        'statuses' => [['id' => 'wamid.OUT2', 'status' => 'sent']],
                    ],
                ]],
            ]],
        ];

        $this->postSigned($payload)->assertOk();

        $this->assertSame(CommunicationStatus::Read, $communication->fresh()->status);
    }

    /**
     * @return array<string, mixed>
     */
    private function inboundPayload(string $phoneNumberId, string $messageId, string $from, string $body): array
    {
        return [
            'entry' => [[
                'changes' => [[
                    'value' => [
                        'metadata' => ['phone_number_id' => $phoneNumberId],
                        'messages' => [[
                            'id' => $messageId,
                            'from' => $from,
                            'type' => 'text',
                            'text' => ['body' => $body],
                        ]],
                    ],
                ]],
            ]],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function postSigned(array $payload): TestResponse
    {
        $body = json_encode($payload);
        $signature = 'sha256='.hash_hmac('sha256', $body, 'test-app-secret');

        return $this->call('POST', '/webhooks/whatsapp', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => $signature,
        ], $body);
    }
}
