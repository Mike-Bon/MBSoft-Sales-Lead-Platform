<?php

namespace App\Services\Ai\Tools;

use App\Contracts\Ai\AgentTool;
use App\Enums\CommunicationChannel;
use App\Enums\WhatsAppNumberStatus;
use App\Models\User;
use App\Models\WhatsAppBusinessNumber;
use App\Services\Communication\CommunicationAuthorizer;
use App\Services\Communication\CommunicationService;
use App\Support\Ai\ToolDefinition;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

/**
 * Mirrors DraftEmailTool exactly — see its docblock. Never sends, never
 * calls CommunicationService::sendWhatsApp().
 */
class DraftWhatsAppTool implements AgentTool
{
    public function __construct(
        private readonly CommunicationAuthorizer $authorizer,
        private readonly CommunicationService $communications,
    ) {}

    public function definition(): ToolDefinition
    {
        return new ToolDefinition(
            name: 'draft_whatsapp',
            description: 'Prepare a DRAFT WhatsApp message for human review — this never sends anything. Returns the drafted recipient/body for the user to confirm, edit, or discard. Either raw body, or a template_id, must be provided.',
            parameters: [
                'type' => 'object',
                'properties' => [
                    'recipient' => ['type' => 'string', 'description' => 'Recipient phone number, E.164 format.'],
                    'body' => ['type' => 'string'],
                    'template_id' => ['type' => 'integer', 'description' => 'Use this template instead of a raw body.'],
                    'whatsapp_number_id' => ['type' => 'integer', 'description' => 'Which connected business number to send from. If omitted and exactly one is available to the user, it is used automatically.'],
                    'organization_id' => ['type' => 'integer'],
                    'contact_id' => ['type' => 'integer'],
                    'lead_id' => ['type' => 'integer'],
                    'opportunity_id' => ['type' => 'integer'],
                ],
                'required' => ['recipient'],
            ],
        );
    }

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function execute(User $actor, array $arguments): array
    {
        $organizationId = $arguments['organization_id'] ?? null;
        $contactId = $arguments['contact_id'] ?? null;
        $leadId = $arguments['lead_id'] ?? null;
        $opportunityId = $arguments['opportunity_id'] ?? null;

        $this->authorizer->authorizeCrmAttachment($actor, $organizationId, $contactId, $leadId, $opportunityId);

        $number = $this->resolveNumber($actor, $arguments['whatsapp_number_id'] ?? null);

        if ($number === null) {
            return [
                'draft' => false,
                'reason' => 'no_whatsapp_number_available',
                'message' => 'No connected WhatsApp business number is available to this user.',
            ];
        }
        if (is_array($number)) {
            // Multiple candidates and none specified — hand the choice
            // back rather than silently guessing which one to send from.
            return [
                'draft' => false,
                'reason' => 'multiple_whatsapp_numbers_available',
                'available_numbers' => $number,
                'message' => 'More than one WhatsApp number is available. Ask the user which one to send from, then retry with whatsapp_number_id.',
            ];
        }

        if (isset($arguments['template_id'])) {
            $rendered = $this->communications->previewTemplate(
                $actor,
                (int) $arguments['template_id'],
                CommunicationChannel::WhatsApp,
                $organizationId,
                $contactId,
                $leadId,
                $opportunityId,
            );
            $body = $rendered['body'];
        } else {
            $body = $arguments['body'] ?? null;
        }

        if (! $body) {
            throw ValidationException::withMessages([
                'body' => 'Provide a body, or a template_id, to draft this WhatsApp message.',
            ]);
        }

        return [
            'draft' => true,
            'channel' => 'whatsapp',
            'recipient' => $arguments['recipient'],
            'body' => $body,
            'whatsapp_number_id' => $number->id,
            'whatsapp_number_label' => $number->display_name.' ('.$number->phone_number.')',
            'organization_id' => $organizationId,
            'contact_id' => $contactId,
            'lead_id' => $leadId,
            'opportunity_id' => $opportunityId,
            'notice' => 'This is a draft only. Nothing has been sent. The user must review and explicitly send it.',
        ];
    }

    /**
     * @return WhatsAppBusinessNumber|list<array{id: int, label: string}>|null
     */
    private function resolveNumber(User $actor, ?int $requestedId): WhatsAppBusinessNumber|array|null
    {
        if ($requestedId !== null) {
            $number = WhatsAppBusinessNumber::find($requestedId);

            if (! $number) {
                return null;
            }

            $this->authorizer->authorizeWhatsAppSend($actor, $number);

            return $number;
        }

        $available = WhatsAppBusinessNumber::query()
            ->where('status', WhatsAppNumberStatus::Connected)
            ->get()
            ->filter(fn (WhatsAppBusinessNumber $n) => $actor->isManager() || $n->team_id === null || $n->team_id === $actor->team_id);

        if ($available->count() === 1) {
            return $available->first();
        }

        if ($available->isEmpty()) {
            return null;
        }

        return $available->map(fn (WhatsAppBusinessNumber $n) => ['id' => $n->id, 'label' => $n->display_name.' ('.$n->phone_number.')'])->all();
    }
}
