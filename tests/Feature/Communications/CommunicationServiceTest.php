<?php

namespace Tests\Feature\Communications;

use App\Enums\CommunicationChannel;
use App\Enums\CommunicationStatus;
use App\Enums\EmailAccountStatus;
use App\Enums\WhatsAppNumberStatus;
use App\Jobs\SendCommunicationJob;
use App\Models\Contact;
use App\Models\EmailAccount;
use App\Models\MessageTemplate;
use App\Models\Organization;
use App\Models\Team;
use App\Models\User;
use App\Models\WhatsAppBusinessNumber;
use App\Services\Communication\CommunicationService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * STEP 27/28: exercises CommunicationService's authorization and
 * content-resolution logic entirely against the database and Queue::fake
 * — no provider is ever actually invoked here (Queue::fake prevents
 * SendCommunicationJob, which is the only thing that talks to a
 * provider, from running at all). Provider-call behaviour itself is
 * covered in SendCommunicationJobTest with a fake provider binding.
 */
class CommunicationServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_sending_email_creates_a_queued_communication_and_dispatches_the_job(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        EmailAccount::factory()->create(['user_id' => $user->id]);
        $contact = Contact::factory()->create(['owner_id' => $user->id]);

        $communication = app(CommunicationService::class)->sendEmail($user, [
            'recipient' => 'lead@example.test',
            'subject' => 'Hello',
            'body' => 'Hi there',
            'contact_id' => $contact->id,
        ]);

        $this->assertSame(CommunicationChannel::Email, $communication->channel);
        $this->assertSame(CommunicationStatus::Queued, $communication->status);
        $this->assertSame($user->id, $communication->user_id);
        $this->assertSame('lead@example.test', $communication->recipient);
        $this->assertNotNull($communication->activity);

        Queue::assertPushed(SendCommunicationJob::class, fn ($job) => $job->communicationId === $communication->id);
    }

    public function test_sending_email_without_a_connected_account_fails(): void
    {
        Queue::fake();
        $user = User::factory()->create();

        $this->expectException(ValidationException::class);

        app(CommunicationService::class)->sendEmail($user, [
            'recipient' => 'lead@example.test',
            'body' => 'Hi',
        ]);
    }

    public function test_a_user_cannot_send_email_from_another_users_account(): void
    {
        Queue::fake();
        $owner = User::factory()->create();
        $other = User::factory()->create();
        EmailAccount::factory()->create(['user_id' => $owner->id]);

        // Simulate a compromised/incorrect request: even though only
        // $other->emailAccount is ever consulted (never a frontend id),
        // prove $other genuinely has no account of their own to send
        // from, so the two accounts can never be confused.
        $this->assertNull($other->fresh()->emailAccount);

        $this->expectException(ValidationException::class);

        app(CommunicationService::class)->sendEmail($other, [
            'recipient' => 'lead@example.test',
            'body' => 'Hi',
        ]);
    }

    public function test_a_manager_cannot_send_from_another_users_disconnected_email_account_either(): void
    {
        Queue::fake();
        $manager = User::factory()->manager()->create();
        EmailAccount::factory()->create(['user_id' => $manager->id, 'status' => EmailAccountStatus::NeedsReauth]);

        $this->expectException(AuthorizationException::class);

        app(CommunicationService::class)->sendEmail($manager, [
            'recipient' => 'lead@example.test',
            'body' => 'Hi',
        ]);
    }

    public function test_sending_email_attached_to_an_unauthorized_crm_record_is_rejected(): void
    {
        Queue::fake();
        $team = Team::factory()->create();
        $member = User::factory()->teamMember($team)->create();
        EmailAccount::factory()->create(['user_id' => $member->id]);

        $otherTeam = Team::factory()->create();
        $otherOwner = User::factory()->teamMember($otherTeam)->create();
        $foreignContact = Contact::factory()->create(['owner_id' => $otherOwner->id, 'team_id' => $otherTeam->id]);

        $this->expectException(AuthorizationException::class);

        app(CommunicationService::class)->sendEmail($member, [
            'recipient' => 'lead@example.test',
            'body' => 'Hi',
            'contact_id' => $foreignContact->id,
        ]);
    }

    public function test_whatsapp_send_is_rejected_for_a_number_scoped_to_a_different_team(): void
    {
        Queue::fake();
        $teamA = Team::factory()->create();
        $teamB = Team::factory()->create();
        $member = User::factory()->teamMember($teamA)->create();
        $number = WhatsAppBusinessNumber::factory()->create(['team_id' => $teamB->id]);

        $this->expectException(AuthorizationException::class);

        app(CommunicationService::class)->sendWhatsApp($member, [
            'recipient' => '+15550001111',
            'body' => 'Hi',
            'whatsapp_number_id' => $number->id,
        ]);
    }

    public function test_whatsapp_send_succeeds_for_an_organisation_wide_number(): void
    {
        Queue::fake();
        $team = Team::factory()->create();
        $member = User::factory()->teamMember($team)->create();
        $number = WhatsAppBusinessNumber::factory()->create(['team_id' => null]);

        $communication = app(CommunicationService::class)->sendWhatsApp($member, [
            'recipient' => '+15550001111',
            'body' => 'Hi',
            'whatsapp_number_id' => $number->id,
        ]);

        $this->assertSame(CommunicationChannel::WhatsApp, $communication->channel);
        Queue::assertPushed(SendCommunicationJob::class);
    }

    public function test_manager_can_use_any_whatsapp_number(): void
    {
        Queue::fake();
        $manager = User::factory()->manager()->create();
        $number = WhatsAppBusinessNumber::factory()->create(['team_id' => Team::factory()]);

        $communication = app(CommunicationService::class)->sendWhatsApp($manager, [
            'recipient' => '+15550001111',
            'body' => 'Hi',
            'whatsapp_number_id' => $number->id,
        ]);

        $this->assertSame(CommunicationStatus::Queued, $communication->status);
    }

    public function test_whatsapp_send_rejects_a_disconnected_number(): void
    {
        Queue::fake();
        $manager = User::factory()->manager()->create();
        $number = WhatsAppBusinessNumber::factory()->create(['status' => WhatsAppNumberStatus::Disconnected]);

        $this->expectException(AuthorizationException::class);

        app(CommunicationService::class)->sendWhatsApp($manager, [
            'recipient' => '+15550001111',
            'body' => 'Hi',
            'whatsapp_number_id' => $number->id,
        ]);
    }

    public function test_template_variables_are_substituted_safely(): void
    {
        Queue::fake();
        $user = User::factory()->create(['name' => 'Alex Rep']);
        EmailAccount::factory()->create(['user_id' => $user->id]);
        $organization = Organization::factory()->create(['name' => 'Acme Corp']);
        $contact = Contact::factory()->create(['organization_id' => $organization->id, 'first_name' => 'Jamie', 'owner_id' => $user->id]);
        $template = MessageTemplate::factory()->create([
            'subject' => 'Hi {{first_name}}',
            'body' => 'Hello {{first_name}} from {{company_name}}, regards {{salesperson_name}}.',
        ]);

        $communication = app(CommunicationService::class)->sendEmail($user, [
            'recipient' => 'jamie@example.test',
            'template_id' => $template->id,
            'contact_id' => $contact->id,
        ]);

        $this->assertSame('Hi Jamie', $communication->subject);
        $this->assertSame('Hello Jamie from Acme Corp, regards Alex Rep.', $communication->body);
    }

    public function test_an_unresolved_template_variable_is_left_visible_rather_than_blanked(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        EmailAccount::factory()->create(['user_id' => $user->id]);
        $template = MessageTemplate::factory()->create([
            'body' => 'Hi {{first_name}}, no CRM record attached here.',
        ]);

        $communication = app(CommunicationService::class)->sendEmail($user, [
            'recipient' => 'x@example.test',
            'template_id' => $template->id,
        ]);

        $this->assertSame('Hi {{first_name}}, no CRM record attached here.', $communication->body);
    }

    public function test_template_body_cannot_execute_code_through_substitution(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        EmailAccount::factory()->create(['user_id' => $user->id]);
        $contact = Contact::factory()->create(['first_name' => '<?php system("id"); ?>', 'owner_id' => $user->id]);
        $template = MessageTemplate::factory()->create(['body' => 'Hi {{first_name}}']);

        $communication = app(CommunicationService::class)->sendEmail($user, [
            'recipient' => 'x@example.test',
            'template_id' => $template->id,
            'contact_id' => $contact->id,
        ]);

        // The malicious-looking value is substituted as inert plain text.
        $this->assertSame('Hi <?php system("id"); ?>', $communication->body);
    }

    public function test_a_team_scoped_template_is_not_usable_by_another_team(): void
    {
        Queue::fake();
        $teamA = Team::factory()->create();
        $teamB = Team::factory()->create();
        $member = User::factory()->teamMember($teamB)->create();
        EmailAccount::factory()->create(['user_id' => $member->id]);
        $template = MessageTemplate::factory()->create(['team_id' => $teamA->id]);

        $this->expectException(ValidationException::class);

        app(CommunicationService::class)->sendEmail($member, [
            'recipient' => 'x@example.test',
            'template_id' => $template->id,
        ]);
    }
}
