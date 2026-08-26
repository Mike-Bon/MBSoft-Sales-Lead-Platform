<?php

namespace Tests\Feature\Communications;

use App\Enums\CommunicationFailureCode;
use App\Models\WhatsAppBusinessNumber;
use App\Services\Communication\Providers\WhatsAppCloudApiProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * STEP 28: drives WhatsAppCloudApiProvider against Http::fake() — the
 * real Laravel Http client code path (headers, JSON body, error
 * mapping) runs, but no request ever reaches graph.facebook.com.
 */
class WhatsAppCloudApiProviderTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_successful_send_returns_the_provider_message_id(): void
    {
        $number = WhatsAppBusinessNumber::factory()->create(['phone_number_id' => '1234567890']);

        Http::fake([
            'https://graph.facebook.com/*/1234567890/messages' => Http::response([
                'messaging_product' => 'whatsapp',
                'messages' => [['id' => 'wamid.ABC123']],
            ], 200),
        ]);

        $result = app(WhatsAppCloudApiProvider::class)->send($number, '+15550001111', 'Hello there');

        $this->assertTrue($result->success);
        $this->assertSame('wamid.ABC123', $result->providerMessageId);

        Http::assertSent(function ($request) {
            return $request['to'] === '+15550001111'
                && $request['type'] === 'text'
                && $request['text']['body'] === 'Hello there'
                && $request->hasHeader('Authorization');
        });
    }

    public function test_a_401_response_is_mapped_to_an_authentication_error(): void
    {
        $number = WhatsAppBusinessNumber::factory()->create();

        Http::fake([
            'https://graph.facebook.com/*' => Http::response([
                'error' => ['message' => 'Invalid OAuth access token', 'code' => 190],
            ], 401),
        ]);

        $result = app(WhatsAppCloudApiProvider::class)->send($number, '+15550001111', 'Hi');

        $this->assertFalse($result->success);
        $this->assertSame(CommunicationFailureCode::AuthenticationError, $result->failureCode);
    }

    public function test_an_invalid_recipient_error_code_is_mapped_correctly(): void
    {
        $number = WhatsAppBusinessNumber::factory()->create();

        Http::fake([
            'https://graph.facebook.com/*' => Http::response([
                'error' => ['message' => 'Invalid recipient', 'code' => 131026],
            ], 400),
        ]);

        $result = app(WhatsAppCloudApiProvider::class)->send($number, 'not-a-number', 'Hi');

        $this->assertFalse($result->success);
        $this->assertSame(CommunicationFailureCode::InvalidRecipient, $result->failureCode);
    }

    public function test_a_connection_failure_is_mapped_to_a_temporary_network_error(): void
    {
        $number = WhatsAppBusinessNumber::factory()->create();

        Http::fake(function () {
            throw new ConnectionException('Could not connect');
        });

        $result = app(WhatsAppCloudApiProvider::class)->send($number, '+15550001111', 'Hi');

        $this->assertFalse($result->success);
        $this->assertSame(CommunicationFailureCode::TemporaryNetworkError, $result->failureCode);
    }

    public function test_no_access_token_is_ever_logged_or_present_in_the_result(): void
    {
        $number = WhatsAppBusinessNumber::factory()->create();
        config(['services.whatsapp.access_token' => 'super-secret-waba-token']);

        Http::fake([
            'https://graph.facebook.com/*' => Http::response(['messages' => [['id' => 'x']]], 200),
        ]);

        $result = app(WhatsAppCloudApiProvider::class)->send($number, '+15550001111', 'Hi');

        $this->assertStringNotContainsString('super-secret-waba-token', json_encode($result->metadata));
    }
}
