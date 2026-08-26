<?php

namespace Tests\Feature\Workflow;

use App\Enums\ApprovalStatus;
use App\Models\Communication;
use App\Models\Contact;
use App\Models\EmailAccount;
use App\Models\User;
use App\Models\WhatsAppBusinessNumber;
use App\Models\WorkflowApproval;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * STEP 20/39/40/41: an approval never sends anything by itself.
 * "Approve & Send" is the prefilled composer (CommunicationController)
 * submitting to CommunicationService, exactly like an ordinary manual
 * send — these tests exercise that whole path, including revalidation.
 */
class ApprovalFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_creating_an_approval_never_sends_anything(): void
    {
        WorkflowApproval::factory()->create();

        $this->assertDatabaseCount('communications', 0);
    }

    public function test_a_pending_approval_appears_in_the_users_queue(): void
    {
        $user = User::factory()->create();
        $approval = WorkflowApproval::factory()->create(['user_id' => $user->id]);

        $this->actingAs($user)->get('/workflows/approvals')
            ->assertOk()
            ->assertSee($approval->recipient);
    }

    public function test_a_user_cannot_see_another_users_approval(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $approval = WorkflowApproval::factory()->create(['user_id' => $owner->id, 'recipient' => 'secret-recipient@example.test']);

        $this->actingAs($other)->get('/workflows/approvals')->assertDontSee('secret-recipient@example.test');
    }

    public function test_rejecting_an_approval_marks_it_rejected_and_sends_nothing(): void
    {
        $user = User::factory()->create();
        $approval = WorkflowApproval::factory()->create(['user_id' => $user->id]);

        $this->actingAs($user)->post("/workflows/approvals/{$approval->id}/reject")
            ->assertRedirect(route('workflows.approvals.index'));

        $this->assertSame(ApprovalStatus::Rejected, $approval->fresh()->status);
        $this->assertDatabaseCount('communications', 0);
    }

    public function test_a_user_cannot_reject_another_users_approval(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $approval = WorkflowApproval::factory()->create(['user_id' => $owner->id]);

        $this->actingAs($other)->post("/workflows/approvals/{$approval->id}/reject")->assertForbidden();

        $this->assertSame(ApprovalStatus::Pending, $approval->fresh()->status);
    }

    public function test_approving_sends_through_communicationservice_and_links_the_approval(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        EmailAccount::factory()->create(['user_id' => $user->id]);
        $approval = WorkflowApproval::factory()->create(['user_id' => $user->id, 'recipient' => 'jamie@example.test', 'subject' => 'Hi', 'body' => 'Following up']);

        $this->actingAs($user)->post('/communications/send/email', [
            'recipient' => $approval->recipient,
            'subject' => $approval->subject,
            'body' => $approval->body,
            'workflow_approval_id' => $approval->id,
            'confirm' => '1',
        ])->assertRedirect();

        $communication = Communication::firstOrFail();
        $this->assertSame($approval->id, $communication->workflow_approval_id);
        $this->assertSame(ApprovalStatus::Approved, $approval->fresh()->status);
        $this->assertSame($user->id, $approval->fresh()->decided_by);
        $this->assertNotNull($approval->fresh()->decided_at);
    }

    public function test_an_expired_approval_cannot_be_used_to_send(): void
    {
        $user = User::factory()->create();
        EmailAccount::factory()->create(['user_id' => $user->id]);
        $approval = WorkflowApproval::factory()->expired()->create(['user_id' => $user->id]);

        $this->actingAs($user)->post('/communications/send/email', [
            'recipient' => $approval->recipient,
            'subject' => $approval->subject,
            'body' => $approval->body,
            'workflow_approval_id' => $approval->id,
            'confirm' => '1',
        ])->assertSessionHasErrors('workflow_approval_id');

        $this->assertDatabaseCount('communications', 0);
    }

    public function test_an_already_decided_approval_cannot_be_reused_to_send_again(): void
    {
        // STEP 40/51: prevents a duplicate send via a second submission
        // referencing the same, already-approved approval.
        $user = User::factory()->create();
        EmailAccount::factory()->create(['user_id' => $user->id]);
        $approval = WorkflowApproval::factory()->approved()->create(['user_id' => $user->id]);

        $this->actingAs($user)->post('/communications/send/email', [
            'recipient' => $approval->recipient,
            'subject' => $approval->subject,
            'body' => $approval->body,
            'workflow_approval_id' => $approval->id,
            'confirm' => '1',
        ])->assertSessionHasErrors('workflow_approval_id');

        $this->assertDatabaseCount('communications', 0);
    }

    public function test_a_user_cannot_send_using_another_users_approval_id(): void
    {
        $owner = User::factory()->create();
        $attacker = User::factory()->create();
        EmailAccount::factory()->create(['user_id' => $attacker->id]);
        $approval = WorkflowApproval::factory()->create(['user_id' => $owner->id]);

        $this->actingAs($attacker)->post('/communications/send/email', [
            'recipient' => 'attacker-target@example.test',
            'subject' => 'Hi',
            'body' => 'Hi',
            'workflow_approval_id' => $approval->id,
            'confirm' => '1',
        ])->assertSessionHasErrors('workflow_approval_id');

        $this->assertSame(ApprovalStatus::Pending, $approval->fresh()->status);
    }

    public function test_the_underlying_crm_record_being_deleted_before_approval_blocks_the_send(): void
    {
        // STEP 40 point 2: verifies the CRM record still exists —
        // reuses CommunicationAuthorizer::authorizeCrmAttachment, which
        // already throws when the referenced record is gone.
        $user = User::factory()->create();
        EmailAccount::factory()->create(['user_id' => $user->id]);
        $contact = Contact::factory()->create(['owner_id' => $user->id]);
        $approval = WorkflowApproval::factory()->create(['user_id' => $user->id, 'contact_id' => $contact->id]);
        $contact->delete();

        $this->actingAs($user)->post('/communications/send/email', [
            'recipient' => $approval->recipient,
            'subject' => $approval->subject,
            'body' => $approval->body,
            'contact_id' => $contact->id,
            'workflow_approval_id' => $approval->id,
            'confirm' => '1',
        ])->assertForbidden();

        $this->assertSame(ApprovalStatus::Pending, $approval->fresh()->status);
    }

    public function test_whatsapp_approval_can_be_approved_and_sent(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $number = WhatsAppBusinessNumber::factory()->create(['team_id' => null]);
        $approval = WorkflowApproval::factory()->whatsapp()->create(['user_id' => $user->id, 'whatsapp_number_id' => $number->id]);

        $this->actingAs($user)->post('/communications/send/whatsapp', [
            'recipient' => $approval->recipient,
            'body' => $approval->body,
            'whatsapp_number_id' => $number->id,
            'workflow_approval_id' => $approval->id,
            'confirm' => '1',
        ])->assertRedirect();

        $this->assertSame(ApprovalStatus::Approved, $approval->fresh()->status);
    }

    public function test_the_compose_page_prefills_from_a_valid_approval(): void
    {
        $user = User::factory()->create();
        EmailAccount::factory()->create(['user_id' => $user->id]);
        $approval = WorkflowApproval::factory()->create(['user_id' => $user->id, 'recipient' => 'preview@example.test', 'body' => 'Preview body text']);

        $this->actingAs($user)->get("/communications/compose/email?workflow_approval_id={$approval->id}")
            ->assertOk()
            ->assertSee('preview@example.test', false)
            ->assertSee('Preview body text', false);
    }

    public function test_the_compose_page_does_not_prefill_from_another_users_approval(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        EmailAccount::factory()->create(['user_id' => $other->id]);
        $approval = WorkflowApproval::factory()->create(['user_id' => $owner->id, 'recipient' => 'private@example.test']);

        $this->actingAs($other)->get("/communications/compose/email?workflow_approval_id={$approval->id}")
            ->assertOk()
            ->assertDontSee('private@example.test', false);
    }
}
