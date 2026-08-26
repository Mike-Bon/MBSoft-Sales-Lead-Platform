<?php

namespace App\Services\Ai\Tools;

use App\Contracts\Ai\AgentTool;
use App\Models\Communication;
use App\Models\User;
use App\Services\Communication\CommunicationAuthorizer;
use App\Support\Ai\ToolDefinition;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * STEP 14: retrieves only the relevant window of communication history
 * for one CRM record — never the whole table, never another team's
 * history. Reuses CommunicationAuthorizer::authorizeCrmAttachment()
 * exactly as CommunicationService does for sending, so "may this user
 * see communications about this record" is answered identically in both
 * directions.
 */
class GetCommunicationHistoryTool implements AgentTool
{
    public function __construct(private readonly CommunicationAuthorizer $authorizer) {}

    public function definition(): ToolDefinition
    {
        return new ToolDefinition(
            name: 'get_communication_history',
            description: 'Retrieve recent email/WhatsApp communication history for one CRM record (contact, lead, or opportunity). Read-only. Exactly one of contact_id/lead_id/opportunity_id is required.',
            parameters: [
                'type' => 'object',
                'properties' => [
                    'contact_id' => ['type' => 'integer'],
                    'lead_id' => ['type' => 'integer'],
                    'opportunity_id' => ['type' => 'integer'],
                    'limit' => ['type' => 'integer', 'description' => 'Maximum results to return (default 10, max 25).'],
                ],
            ],
        );
    }

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function execute(User $actor, array $arguments): array
    {
        $contactId = $arguments['contact_id'] ?? null;
        $leadId = $arguments['lead_id'] ?? null;
        $opportunityId = $arguments['opportunity_id'] ?? null;

        if (! $contactId && ! $leadId && ! $opportunityId) {
            throw ValidationException::withMessages([
                'contact_id' => 'Provide a contact_id, lead_id, or opportunity_id to look up communication history for.',
            ]);
        }

        $this->authorizer->authorizeCrmAttachment($actor, null, $contactId, $leadId, $opportunityId);

        $limit = min((int) ($arguments['limit'] ?? 10), 25);

        $query = Communication::query();

        if ($contactId) {
            $query->where('contact_id', $contactId);
        }
        if ($leadId) {
            $query->where('lead_id', $leadId);
        }
        if ($opportunityId) {
            $query->where('opportunity_id', $opportunityId);
        }

        $communications = $query->orderByDesc('created_at')->limit($limit)->get();

        return [
            'count' => $communications->count(),
            'communications' => $communications->map(fn (Communication $c) => [
                'channel' => $c->channel->label(),
                'direction' => $c->direction->label(),
                'status' => $c->status->label(),
                'subject' => $c->subject,
                // Data minimization (STEP 14): a summary, not the full
                // body — enough for the agent to reason about "what was
                // said", not a full transcript dump.
                'summary' => Str::limit($c->body, 200),
                'date' => $c->created_at->toDateTimeString(),
            ])->all(),
        ];
    }
}
