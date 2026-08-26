<?php

namespace Tests\Feature\Communications;

use App\Contracts\Communication\EmailProvider;
use App\Contracts\Communication\WhatsAppProvider;
use App\Enums\CommunicationFailureCode;
use App\Enums\CommunicationStatus;
use App\Jobs\SendCommunicationJob;
use App\Models\Communication;
use App\Models\EmailAccount;
use App\Models\User;
use App\Support\Communication\ProviderSendResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * STEP 28: exercises SendCommunicationJob against fake providers bound
 * into the container — no real Gmail/WhatsApp API call is ever made
 * from the automated suite. See tests/Manual/COMMUNICATIONS_MANUAL_
 * VERIFICATION.md (STEP 29) for the separate, honest, real-credential
 * checklist.
 */
class SendCommunicationJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_successful_send_marks_the_communication_sent_with_the_provider_message_id(): void
    {
        $user = User::factory()->create();
        $account = EmailAccount::factory()->create(['user_id' => $user->id]);
        $communication = Communication::factory()->create([
            'user_id' => $user->id,
            'email_account_id' => $account->id,
        ]);

        $this->app->bind(EmailProvider::class, fn () => new class implements EmailProvider
        {
            public function send($account, $to, $subject, $body, $threadId = null): ProviderSendResult
            {
                return ProviderSendResult::success('gmail-msg-123', 'gmail-thread-1');
            }
        });

        (new SendCommunicationJob($communication->id))->handle(
            app(EmailProvider::class),
            app(WhatsAppProvider::class),
        );

        $communication->refresh();
        $this->assertSame(CommunicationStatus::Sent, $communication->status);
        $this->assertSame('gmail-msg-123', $communication->provider_message_id);
        $this->assertSame('gmail-thread-1', $communication->provider_thread_id);
        $this->assertNotNull($communication->sent_at);
    }

    public function test_a_non_retryable_failure_is_marked_failed_immediately(): void
    {
        $user = User::factory()->create();
        $account = EmailAccount::factory()->create(['user_id' => $user->id]);
        $communication = Communication::factory()->create([
            'user_id' => $user->id,
            'email_account_id' => $account->id,
        ]);

        $this->app->bind(EmailProvider::class, fn () => new class implements EmailProvider
        {
            public function send($account, $to, $subject, $body, $threadId = null): ProviderSendResult
            {
                return ProviderSendResult::failure(CommunicationFailureCode::InvalidRecipient, 'Bad address');
            }
        });

        (new SendCommunicationJob($communication->id))->handle(
            app(EmailProvider::class),
            app(WhatsAppProvider::class),
        );

        $communication->refresh();
        $this->assertSame(CommunicationStatus::Failed, $communication->status);
        $this->assertSame(CommunicationFailureCode::InvalidRecipient, $communication->failure_code);
        $this->assertNotNull($communication->failed_at);
        $this->assertNull($communication->provider_message_id);
    }

    public function test_a_retryable_failure_does_not_mark_the_communication_terminally_failed(): void
    {
        $user = User::factory()->create();
        $account = EmailAccount::factory()->create(['user_id' => $user->id]);
        $communication = Communication::factory()->create([
            'user_id' => $user->id,
            'email_account_id' => $account->id,
        ]);

        $this->app->bind(EmailProvider::class, fn () => new class implements EmailProvider
        {
            public function send($account, $to, $subject, $body, $threadId = null): ProviderSendResult
            {
                return ProviderSendResult::failure(CommunicationFailureCode::RateLimited, 'Slow down');
            }
        });

        // Direct handle() call has no bound queue Job, so attempts()
        // defaults to 1 (< tries=3) and release() is a safe no-op —
        // this proves the record is left retryable, not terminally
        // failed, exactly as it would be mid-retry on a real worker.
        (new SendCommunicationJob($communication->id))->handle(
            app(EmailProvider::class),
            app(WhatsAppProvider::class),
        );

        $communication->refresh();
        $this->assertFalse($communication->status->isTerminal());
        $this->assertSame(CommunicationFailureCode::RateLimited, $communication->failure_code);
    }

    public function test_an_already_terminal_communication_is_never_sent_again(): void
    {
        $user = User::factory()->create();
        $account = EmailAccount::factory()->create(['user_id' => $user->id]);
        $communication = Communication::factory()->sent()->create([
            'user_id' => $user->id,
            'email_account_id' => $account->id,
        ]);
        $originalMessageId = $communication->provider_message_id;

        $counter = (object) ['calls' => 0];
        $this->app->bind(EmailProvider::class, fn () => new class($counter) implements EmailProvider
        {
            public function __construct(private object $counter) {}

            public function send($account, $to, $subject, $body, $threadId = null): ProviderSendResult
            {
                $this->counter->calls++;

                return ProviderSendResult::success('should-not-be-called');
            }
        });

        (new SendCommunicationJob($communication->id))->handle(
            app(EmailProvider::class),
            app(WhatsAppProvider::class),
        );

        $this->assertSame(0, $counter->calls);
        $this->assertSame($originalMessageId, $communication->fresh()->provider_message_id);
    }

    public function test_a_missing_communication_is_handled_gracefully(): void
    {
        (new SendCommunicationJob(999_999))->handle(
            app(EmailProvider::class),
            app(WhatsAppProvider::class),
        );

        $this->assertTrue(true);
    }
}
