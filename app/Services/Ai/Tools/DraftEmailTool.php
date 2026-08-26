<?php

namespace App\Services\Ai\Tools;

use App\Contracts\Ai\AgentTool;
use App\Enums\CommunicationChannel;
use App\Models\User;
use App\Services\Communication\CommunicationAuthorizer;
use App\Services\Communication\CommunicationService;
use App\Support\Ai\ToolDefinition;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

/**
 * STEP 16/17: produces a DRAFT ONLY — this tool never creates a
 * Communication row and never calls CommunicationService::sendEmail().
 * Its return value is pure structured data for AssistantController to
 * render as a draft preview; the only path from here to an actual send
 * is the human clicking through to the existing, already-tested compose
 * screen (CommunicationController::composeEmail/sendEmail), pre-filled
 * with this draft's content, where the same server-validated
 * confirmation checkbox from Phase 6 still applies.
 */
class DraftEmailTool implements AgentTool
{
    public function __construct(
        private readonly CommunicationAuthorizer $authorizer,
        private readonly CommunicationService $communications,
    ) {}

    public function definition(): ToolDefinition
    {
        return new ToolDefinition(
            name: 'draft_email',
            description: 'Prepare a DRAFT email for human review — this never sends anything. Returns the drafted recipient/subject/body for the user to confirm, edit, or discard. Either raw subject+body, or a template_id, must be provided.',
            parameters: [
                'type' => 'object',
                'properties' => [
                    'recipient' => ['type' => 'string', 'description' => 'Recipient email address.'],
                    'subject' => ['type' => 'string'],
                    'body' => ['type' => 'string'],
                    'template_id' => ['type' => 'integer', 'description' => 'Use this template instead of raw subject/body.'],
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
        if (! $actor->emailAccount) {
            return [
                'draft' => false,
                'reason' => 'no_connected_email_account',
                'message' => 'This user has no connected Gmail account, so an email draft cannot be sent even once approved. Suggest connecting one first.',
            ];
        }

        $organizationId = $arguments['organization_id'] ?? null;
        $contactId = $arguments['contact_id'] ?? null;
        $leadId = $arguments['lead_id'] ?? null;
        $opportunityId = $arguments['opportunity_id'] ?? null;

        $this->authorizer->authorizeCrmAttachment($actor, $organizationId, $contactId, $leadId, $opportunityId);

        if (isset($arguments['template_id'])) {
            $rendered = $this->communications->previewTemplate(
                $actor,
                (int) $arguments['template_id'],
                CommunicationChannel::Email,
                $organizationId,
                $contactId,
                $leadId,
                $opportunityId,
            );
            $subject = $rendered['subject'];
            $body = $rendered['body'];
        } else {
            $subject = $arguments['subject'] ?? null;
            $body = $arguments['body'] ?? null;
        }

        if (! $subject || ! $body) {
            throw ValidationException::withMessages([
                'body' => 'Provide a subject and body, or a template_id, to draft this email.',
            ]);
        }

        return [
            'draft' => true,
            'channel' => 'email',
            'recipient' => $arguments['recipient'],
            'subject' => $subject,
            'body' => $body,
            'organization_id' => $organizationId,
            'contact_id' => $contactId,
            'lead_id' => $leadId,
            'opportunity_id' => $opportunityId,
            'notice' => 'This is a draft only. Nothing has been sent. The user must review and explicitly send it.',
        ];
    }
}
