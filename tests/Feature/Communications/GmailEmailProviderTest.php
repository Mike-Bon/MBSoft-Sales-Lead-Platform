<?php

namespace Tests\Feature\Communications;

use App\Enums\CommunicationFailureCode;
use App\Enums\EmailAccountStatus;
use App\Models\EmailAccount;
use App\Services\Communication\Providers\GmailEmailProvider;
use Google\Client;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * STEP 28 "integration test": drives the real google/apiclient SDK code
 * path (auth token wiring, MIME encoding, Gmail's users.messages.send
 * call, response parsing, error mapping) against a mocked HTTP
 * transport — no request ever leaves the machine, but nothing about
 * Google's client library itself is stubbed out.
 */
class GmailEmailProviderTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_successful_gmail_response_is_mapped_to_a_success_result(): void
    {
        $account = EmailAccount::factory()->create([
            'status' => EmailAccountStatus::Connected,
            'token_expires_at' => now()->addHour(),
        ]);

        $provider = new GmailEmailProvider($this->googleClient([
            new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'id' => 'gmail-msg-abc',
                'threadId' => 'gmail-thread-abc',
            ])),
        ]));

        $result = $provider->send($account, 'to@example.test', 'Subject line', 'Body text');

        $this->assertTrue($result->success);
        $this->assertSame('gmail-msg-abc', $result->providerMessageId);
        $this->assertSame('gmail-thread-abc', $result->providerThreadId);
    }

    public function test_a_401_from_gmail_is_mapped_to_an_authentication_error(): void
    {
        $account = EmailAccount::factory()->create(['token_expires_at' => now()->addHour()]);

        $provider = new GmailEmailProvider($this->googleClient([
            new Response(401, ['Content-Type' => 'application/json'], json_encode([
                'error' => ['code' => 401, 'message' => 'Invalid Credentials'],
            ])),
        ]));

        $result = $provider->send($account, 'to@example.test', 'Subject', 'Body');

        $this->assertFalse($result->success);
        $this->assertSame(CommunicationFailureCode::AuthenticationError, $result->failureCode);
    }

    public function test_a_429_from_gmail_is_mapped_to_rate_limited(): void
    {
        $account = EmailAccount::factory()->create(['token_expires_at' => now()->addHour()]);

        $provider = new GmailEmailProvider($this->googleClient([
            new Response(429, ['Content-Type' => 'application/json'], json_encode([
                'error' => ['code' => 429, 'message' => 'Too many requests'],
            ])),
        ]));

        $result = $provider->send($account, 'to@example.test', 'Subject', 'Body');

        $this->assertFalse($result->success);
        $this->assertSame(CommunicationFailureCode::RateLimited, $result->failureCode);
    }

    /**
     * @param  array<int, Response>  $responses
     */
    private function googleClient(array $responses): Client
    {
        $mock = new MockHandler($responses);
        $handlerStack = HandlerStack::create($mock);
        $guzzle = new GuzzleClient(['handler' => $handlerStack]);

        $client = new Client;
        $client->setHttpClient($guzzle);

        return $client;
    }
}
