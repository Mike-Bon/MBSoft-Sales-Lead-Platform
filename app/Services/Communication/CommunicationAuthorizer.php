<?php

namespace App\Services\Communication;

use App\Enums\EmailAccountStatus;
use App\Enums\WhatsAppNumberStatus;
use App\Models\Contact;
use App\Models\EmailAccount;
use App\Models\Lead;
use App\Models\Opportunity;
use App\Models\Organization;
use App\Models\User;
use App\Models\WhatsAppBusinessNumber;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;

/**
 * STEP 19/20: the server-side authorization gate every send passes
 * through. Never trusts a frontend-supplied owner_id/team_id/account id
 * — every check here re-derives the answer from the authenticated
 * actor's own stored role/team and the account's own stored ownership,
 * exactly like PerformanceAuthorizer does for performance data.
 *
 * Mirrors the existing Manager / Team Head / Team Member hierarchy for
 * which CRM records and WhatsApp numbers may be used, but email sending
 * carries one hard rule with no role exception: a connected Gmail
 * account is a real OAuth identity belonging to one person, so — same
 * as nobody can send email "as" a colleague's real Gmail account — not
 * even a Manager may send from another user's connected email account.
 * Every role may only ever send email from their own.
 *   - Email: sender must own the connected account (all roles).
 *   - WhatsApp: Manager may use any business number; Team Head/Member
 *     may use an organisation-wide number (team_id null) or one scoped
 *     to their own team.
 */
class CommunicationAuthorizer
{
    /**
     * @throws AuthorizationException
     */
    public function authorizeEmailSend(User $actor, EmailAccount $account): void
    {
        if ($account->user_id !== $actor->id) {
            throw new AuthorizationException('You may only send from your own connected email account.');
        }

        if ($account->status !== EmailAccountStatus::Connected) {
            throw new AuthorizationException('This email account is not connected. Reconnect it before sending.');
        }
    }

    /**
     * @throws AuthorizationException
     */
    public function authorizeWhatsAppSend(User $actor, WhatsAppBusinessNumber $number): void
    {
        $allowed = $actor->isManager()
            || $number->team_id === null
            || $number->team_id === $actor->team_id;

        if (! $allowed) {
            throw new AuthorizationException('This WhatsApp number is not available to your team.');
        }

        if ($number->status !== WhatsAppNumberStatus::Connected) {
            throw new AuthorizationException('This WhatsApp number is not connected.');
        }
    }

    /**
     * Verifies the actor may view every non-null CRM record a
     * communication is being attached to, exactly as ActivityService
     * does for ordinary activities — a user could otherwise attach a
     * message to any contact/lead/opportunity/organization id just by
     * guessing it.
     *
     * @throws AuthorizationException
     */
    public function authorizeCrmAttachment(User $actor, ?int $organizationId, ?int $contactId, ?int $leadId, ?int $opportunityId): void
    {
        $this->assertCanView($actor, Organization::class, $organizationId);
        $this->assertCanView($actor, Contact::class, $contactId);
        $this->assertCanView($actor, Lead::class, $leadId);
        $this->assertCanView($actor, Opportunity::class, $opportunityId);
    }

    /**
     * @param  class-string  $modelClass
     *
     * @throws AuthorizationException
     */
    private function assertCanView(User $actor, string $modelClass, ?int $id): void
    {
        if ($id === null) {
            return;
        }

        $record = $modelClass::find($id);

        if (! $record || Gate::forUser($actor)->denies('view', $record)) {
            throw new AuthorizationException('You are not authorized to communicate about one of the selected records.');
        }
    }
}
