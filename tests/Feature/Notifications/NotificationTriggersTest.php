<?php

namespace Tests\Feature\Notifications;

use App\Contracts\Ai\LlmProvider;
use App\Contracts\Communication\EmailProvider;
use App\Contracts\Communication\WhatsAppProvider;
use App\Enums\AgentIdentifier;
use App\Enums\CommunicationFailureCode;
use App\Enums\WorkflowType;
use App\Jobs\SendCommunicationJob;
use App\Models\Communication;
use App\Models\Contact;
use App\Models\EmailAccount;
use App\Models\User;
use App\Models\WorkflowApproval;
use App\Notifications\CommunicationFailedNotification;
use App\Notifications\WorkflowApprovalPendingNotification;
use App\Services\Workflow\WorkflowExecutionService;
use App\Support\Communication\ProviderSendResult;
use App\Support\Workflow\AnalysisResult;
use App\Support\Workflow\WorkflowScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Support\FakeLlmProvider;
use Tests\TestCase;

/**
 * Phase 11: closing the CLAUDE.md "notifications" V1 gap — the two
 * concrete, narrowly-scoped triggers wired into existing services
 * (never a new send path; both notifications are database-channel
 * only, see each Notification class's own docblock).
 */
class NotificationTriggersTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_new_workflow_approval_notifies_its_owning_user(): void
    {
        Notification::fake();

        $user = User::factory()->create();
        EmailAccount::factory()->create(['user_id' => $user->id]);
        $contact = Contact::factory()->create(['owner_id' => $user->id]);

        $this->app->instance(LlmProvider::class, new FakeLlmProvider([
            FakeLlmProvider::toolCall('draft_email', ['recipient' => 'jamie@example.test', 'subject' => 'Hi', 'body' => 'Following up', 'contact_id' => $contact->id]),
            FakeLlmProvider::text('Draft prepared.'),
        ]));

        app(WorkflowExecutionService::class)->run(
            WorkflowType::DailyFollowUpReview,
            AgentIdentifier::Communication,
            WorkflowScope::forUser($user),
            new AnalysisResult(true, ['leads' => [['contact_id' => $contact->id]]], ''),
            'task',
        );

        $approval = WorkflowApproval::firstOrFail();

        Notification::assertSentTo(
            $user,
            WorkflowApprovalPendingNotification::class,
            fn ($notification) => $notification->toArray($user)['workflow_approval_id'] === $approval->id,
        );
    }

    public function test_no_workflow_approval_means_no_notification(): void
    {
        Notification::fake();

        $user = User::factory()->create();

        app(WorkflowExecutionService::class)->run(
            WorkflowType::DailyFollowUpReview,
            AgentIdentifier::Communication,
            WorkflowScope::forUser($user),
            new AnalysisResult(false, [], 'Nothing to review today.'),
            'task',
        );

        Notification::assertNothingSent();
    }

    public function test_a_failed_send_notifies_the_sending_user(): void
    {
        Notification::fake();

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

        Notification::assertSentTo(
            $user,
            CommunicationFailedNotification::class,
            fn ($notification) => $notification->toArray($user)['communication_id'] === $communication->id
                && $notification->toArray($user)['failure_reason'] === 'Bad address',
        );
    }

    public function test_a_successful_send_notifies_nobody(): void
    {
        Notification::fake();

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
                return ProviderSendResult::success('msg-1');
            }
        });

        (new SendCommunicationJob($communication->id))->handle(
            app(EmailProvider::class),
            app(WhatsAppProvider::class),
        );

        Notification::assertNothingSent();
    }
}
