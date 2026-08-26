<?php

namespace App\Services\Ai\Tools;

use App\Contracts\Ai\AgentTool;
use App\Models\Lead;
use App\Models\User;
use App\Support\Ai\ToolDefinition;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;

class GetLeadTool implements AgentTool
{
    public function definition(): ToolDefinition
    {
        return new ToolDefinition(
            name: 'get_lead',
            description: 'Retrieve full detail for one lead by id, if the authenticated user is authorized to view it. Read-only.',
            parameters: [
                'type' => 'object',
                'properties' => [
                    'lead_id' => ['type' => 'integer', 'description' => 'The lead\'s id.'],
                ],
                'required' => ['lead_id'],
            ],
        );
    }

    /**
     * @throws AuthorizationException
     */
    public function execute(User $actor, array $arguments): array
    {
        $lead = Lead::with(['organization', 'contact', 'owner', 'team'])->find($arguments['lead_id'] ?? null);

        if (! $lead || Gate::forUser($actor)->denies('view', $lead)) {
            throw new AuthorizationException('You are not authorized to view this lead.');
        }

        return [
            'id' => $lead->id,
            'organization' => $lead->organization?->name,
            'contact' => $lead->contact?->fullName(),
            'source' => $lead->source,
            'status' => $lead->status->label(),
            'priority' => $lead->priority->label(),
            'estimated_value' => $lead->estimated_value !== null ? (float) $lead->estimated_value : null,
            'currency' => $lead->currency,
            'expected_close_date' => $lead->expected_close_date?->toDateString(),
            'next_follow_up_at' => $lead->next_follow_up_at?->toDateTimeString(),
            'description' => $lead->description,
            'notes' => $lead->notes,
            'owner' => $lead->owner?->name,
            'team' => $lead->team?->name,
        ];
    }
}
