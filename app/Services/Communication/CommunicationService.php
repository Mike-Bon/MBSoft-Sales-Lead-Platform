<?php

namespace App\Services\Communication;

use App\Enums\ActivityType;
use App\Enums\CommunicationChannel;
use App\Enums\CommunicationDirection;
use App\Enums\CommunicationStatus;
use App\Jobs\SendCommunicationJob;
use App\Models\Communication;
use App\Models\Contact;
use App\Models\Lead;
use App\Models\MessageTemplate;
use App\Models\Opportunity;
use App\Models\Organization;
use App\Models\User;
use App\Models\WhatsAppBusinessNumber;
use App\Services\ActivityLogger;
use App\Support\Communication\TemplateRenderer;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * STEP 2: the single orchestrator every send passes through. Controllers
 * never call a provider or build a Communication row directly — they
 * call sendEmail()/sendWhatsApp() here, which:
 *   1. resolves the sending account server-side and authorizes it
 *      (CommunicationAuthorizer — never trusts a frontend account id
 *      beyond "which one was requested"),
 *   2. authorizes the CRM record(s) being attached,
 *   3. renders the message body (raw, or via a template),
 *   4. writes a QUEUED Communication + linked Activity row,
 *   5. dispatches SendCommunicationJob to actually talk to the
 *      provider (STEP 21: queued execution — the provider is never
 *      called inline from a web request).
 *
 * STEP 18 (manual send only): this class is only ever invoked from a
 * human-initiated controller action behind CommunicationController's
 * "confirm and send" step. Nothing in this codebase calls it
 * automatically, on a schedule, or from AI-generated output.
 */
class CommunicationService
{
    public function __construct(
        private readonly CommunicationAuthorizer $authorizer,
        private readonly ActivityLogger $activities,
        private readonly TemplateRenderer $templates,
    ) {}

    /**
     * @param  array{recipient: string, subject?: ?string, body?: ?string, template_id?: ?int, organization_id?: ?int, contact_id?: ?int, lead_id?: ?int, opportunity_id?: ?int}  $data
     */
    public function sendEmail(User $actor, array $data): Communication
    {
        $account = $actor->emailAccount;

        if (! $account) {
            throw ValidationException::withMessages([
                'recipient' => 'Connect a Gmail account before sending email.',
            ]);
        }

        $this->authorizer->authorizeEmailSend($actor, $account);
        $this->authorizer->authorizeCrmAttachment(
            $actor,
            $data['organization_id'] ?? null,
            $data['contact_id'] ?? null,
            $data['lead_id'] ?? null,
            $data['opportunity_id'] ?? null,
        );

        $template = $this->resolveTemplate($actor, $data['template_id'] ?? null, CommunicationChannel::Email);
        [$subject, $body] = $this->resolveContent($actor, $data, $template);

        $communication = new Communication;
        $communication->channel = CommunicationChannel::Email;
        $communication->direction = CommunicationDirection::Outbound;
        $communication->status = CommunicationStatus::Queued;
        $communication->provider = 'gmail';
        $communication->email_account_id = $account->id;
        $communication->template_id = $template?->id;
        $communication->user_id = $actor->id;
        $communication->team_id = $actor->team_id;
        $communication->organization_id = $data['organization_id'] ?? null;
        $communication->contact_id = $data['contact_id'] ?? null;
        $communication->lead_id = $data['lead_id'] ?? null;
        $communication->opportunity_id = $data['opportunity_id'] ?? null;
        $communication->subject = $subject;
        $communication->recipient = $data['recipient'];
        $communication->sender = $account->email_address;
        $communication->body = $body;
        $communication->save();

        $this->logActivity($actor, ActivityType::Email, $communication, $subject);

        SendCommunicationJob::dispatch($communication->id);

        return $communication;
    }

    /**
     * @param  array{recipient: string, body?: ?string, template_id?: ?int, whatsapp_number_id: int, organization_id?: ?int, contact_id?: ?int, lead_id?: ?int, opportunity_id?: ?int}  $data
     */
    public function sendWhatsApp(User $actor, array $data): Communication
    {
        $number = WhatsAppBusinessNumber::find($data['whatsapp_number_id'] ?? null);

        if (! $number) {
            throw ValidationException::withMessages([
                'whatsapp_number_id' => 'Select a valid WhatsApp business number.',
            ]);
        }

        $this->authorizer->authorizeWhatsAppSend($actor, $number);
        $this->authorizer->authorizeCrmAttachment(
            $actor,
            $data['organization_id'] ?? null,
            $data['contact_id'] ?? null,
            $data['lead_id'] ?? null,
            $data['opportunity_id'] ?? null,
        );

        $template = $this->resolveTemplate($actor, $data['template_id'] ?? null, CommunicationChannel::WhatsApp);
        [, $body] = $this->resolveContent($actor, $data, $template);

        $communication = new Communication;
        $communication->channel = CommunicationChannel::WhatsApp;
        $communication->direction = CommunicationDirection::Outbound;
        $communication->status = CommunicationStatus::Queued;
        $communication->provider = 'whatsapp_cloud_api';
        $communication->whatsapp_number_id = $number->id;
        $communication->template_id = $template?->id;
        $communication->user_id = $actor->id;
        $communication->team_id = $actor->team_id;
        $communication->organization_id = $data['organization_id'] ?? null;
        $communication->contact_id = $data['contact_id'] ?? null;
        $communication->lead_id = $data['lead_id'] ?? null;
        $communication->opportunity_id = $data['opportunity_id'] ?? null;
        $communication->recipient = $data['recipient'];
        $communication->sender = $number->phone_number;
        $communication->body = $body;
        $communication->save();

        $this->logActivity($actor, ActivityType::WhatsApp, $communication, Str::limit($body, 60));

        SendCommunicationJob::dispatch($communication->id);

        return $communication;
    }

    private function resolveTemplate(User $actor, ?int $templateId, CommunicationChannel $channel): ?MessageTemplate
    {
        if ($templateId === null) {
            return null;
        }

        $template = MessageTemplate::find($templateId);

        if (! $template || $template->channel !== $channel) {
            throw ValidationException::withMessages([
                'template_id' => 'Select a valid template for this channel.',
            ]);
        }

        $usable = $actor->isManager() || $template->team_id === null || $template->team_id === $actor->team_id;

        if (! $usable) {
            throw ValidationException::withMessages([
                'template_id' => 'This template is not available to your team.',
            ]);
        }

        return $template;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{0: ?string, 1: string}
     */
    private function resolveContent(User $actor, array $data, ?MessageTemplate $template): array
    {
        if ($template === null) {
            return [$data['subject'] ?? null, (string) ($data['body'] ?? '')];
        }

        $variables = $this->resolveTemplateVariables(
            $actor,
            $data['organization_id'] ?? null,
            $data['contact_id'] ?? null,
            $data['lead_id'] ?? null,
            $data['opportunity_id'] ?? null,
        );

        $subject = $template->subject !== null ? $this->templates->render($template->subject, $variables) : null;
        $body = $this->templates->render($template->body, $variables);

        return [$subject, $body];
    }

    /**
     * @return array<string, ?string>
     */
    private function resolveTemplateVariables(User $actor, ?int $organizationId, ?int $contactId, ?int $leadId, ?int $opportunityId): array
    {
        $contact = $contactId ? Contact::find($contactId) : null;
        $contact ??= $leadId ? Lead::find($leadId)?->contact : null;
        $contact ??= $opportunityId ? Opportunity::find($opportunityId)?->contact : null;

        $organization = $organizationId ? Organization::find($organizationId) : null;
        $organization ??= $contact?->organization;
        $organization ??= $leadId ? Lead::find($leadId)?->organization : null;
        $organization ??= $opportunityId ? Opportunity::find($opportunityId)?->organization : null;

        return [
            'first_name' => $contact?->first_name,
            'company_name' => $organization?->name,
            'salesperson_name' => $actor->name,
        ];
    }

    private function logActivity(User $actor, ActivityType $type, Communication $communication, ?string $subject): void
    {
        $this->activities->log($actor, $type, [
            'organization_id' => $communication->organization_id,
            'contact_id' => $communication->contact_id,
            'lead_id' => $communication->lead_id,
            'opportunity_id' => $communication->opportunity_id,
            'subject' => $subject,
            'description' => 'Sent to '.$communication->recipient,
            'occurred_at' => now(),
            'communication_id' => $communication->id,
        ]);
    }
}
