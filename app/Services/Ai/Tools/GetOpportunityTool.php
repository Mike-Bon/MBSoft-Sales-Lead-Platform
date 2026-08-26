<?php

namespace App\Services\Ai\Tools;

use App\Contracts\Ai\AgentTool;
use App\Models\Opportunity;
use App\Models\User;
use App\Support\Ai\ToolDefinition;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;

class GetOpportunityTool implements AgentTool
{
    public function definition(): ToolDefinition
    {
        return new ToolDefinition(
            name: 'get_opportunity',
            description: 'Retrieve full detail for one opportunity by id, if the authenticated user is authorized to view it. Read-only.',
            parameters: [
                'type' => 'object',
                'properties' => [
                    'opportunity_id' => ['type' => 'integer', 'description' => 'The opportunity\'s id.'],
                ],
                'required' => ['opportunity_id'],
            ],
        );
    }

    /**
     * @throws AuthorizationException
     */
    public function execute(User $actor, array $arguments): array
    {
        $opportunity = Opportunity::with(['organization', 'contact', 'lead', 'owner', 'team'])->find($arguments['opportunity_id'] ?? null);

        if (! $opportunity || Gate::forUser($actor)->denies('view', $opportunity)) {
            throw new AuthorizationException('You are not authorized to view this opportunity.');
        }

        return [
            'id' => $opportunity->id,
            'name' => $opportunity->name,
            'organization' => $opportunity->organization?->name,
            'contact' => $opportunity->contact?->fullName(),
            'lead_id' => $opportunity->lead_id,
            'stage' => $opportunity->stage->label(),
            'value' => $opportunity->value !== null ? (float) $opportunity->value : null,
            'currency' => $opportunity->currency,
            'probability' => $opportunity->probability,
            'expected_close_date' => $opportunity->expected_close_date?->toDateString(),
            'closed_at' => $opportunity->closed_at?->toDateTimeString(),
            'description' => $opportunity->description,
            'notes' => $opportunity->notes,
            'owner' => $opportunity->owner?->name,
            'team' => $opportunity->team?->name,
        ];
    }
}
