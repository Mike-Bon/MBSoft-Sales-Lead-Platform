<?php

namespace App\Services\Ai\Tools;

use App\Contracts\Ai\AgentTool;
use App\Enums\LeadPriority;
use App\Enums\LeadStatus;
use App\Http\Controllers\Concerns\ScopesCrmQueries;
use App\Models\Lead;
use App\Models\User;
use App\Support\Ai\ToolDefinition;

/**
 * Mirrors LeadController::index's own filters exactly, so the agent can
 * never see a lead that page wouldn't also show the same user — reuses
 * the identical ScopesCrmQueries::scopeToUser() authorization primitive,
 * not a reimplementation of it.
 */
class SearchLeadsTool implements AgentTool
{
    use ScopesCrmQueries;

    public function definition(): ToolDefinition
    {
        return new ToolDefinition(
            name: 'search_leads',
            description: 'Search the authenticated user\'s own authorized leads using supported business filters. Read-only. Never returns leads outside the user\'s authorization scope, regardless of any team_id/owner_id supplied.',
            parameters: [
                'type' => 'object',
                'properties' => [
                    'status' => ['type' => 'string', 'enum' => array_column(LeadStatus::cases(), 'value'), 'description' => 'Filter by lead status.'],
                    'priority' => ['type' => 'string', 'enum' => array_column(LeadPriority::cases(), 'value'), 'description' => 'Filter by lead priority.'],
                    'owner_id' => ['type' => 'integer', 'description' => 'Filter to a specific owner. Ignored if the requesting user is not authorized to view that owner\'s leads.'],
                    'team_id' => ['type' => 'integer', 'description' => 'Filter to a specific team. Ignored if the requesting user is not authorized to view that team\'s leads.'],
                    'search' => ['type' => 'string', 'description' => 'Free-text match against the related organization or contact name.'],
                    'limit' => ['type' => 'integer', 'description' => 'Maximum results to return (default 10, max 25).'],
                ],
            ],
        );
    }

    public function execute(User $actor, array $arguments): array
    {
        $query = $this->scopeToUser(Lead::query()->with(['organization', 'contact', 'owner', 'team']), $actor);

        if ($status = $arguments['status'] ?? null) {
            $query->where('status', $status);
        }

        if ($priority = $arguments['priority'] ?? null) {
            $query->where('priority', $priority);
        }

        // Not trusted as an authorization bypass: scopeToUser() above
        // already restricted the query to what $actor may see, so these
        // additional filters can only ever narrow that same result set.
        if ($ownerId = $arguments['owner_id'] ?? null) {
            $query->where('owner_id', $ownerId);
        }

        if ($teamId = $arguments['team_id'] ?? null) {
            $query->where('leads.team_id', $teamId);
        }

        if ($search = $arguments['search'] ?? null) {
            $query->where(function ($query) use ($search) {
                $query->whereHas('organization', fn ($q) => $q->where('name', 'like', "%{$search}%"))
                    ->orWhereHas('contact', fn ($q) => $q->where('first_name', 'like', "%{$search}%")->orWhere('last_name', 'like', "%{$search}%"));
            });
        }

        $limit = min((int) ($arguments['limit'] ?? 10), 25);

        $leads = $query->orderByDesc('created_at')->limit($limit)->get();

        return [
            'count' => $leads->count(),
            'leads' => $leads->map(fn (Lead $lead) => [
                'id' => $lead->id,
                'organization' => $lead->organization?->name,
                'contact' => $lead->contact?->fullName(),
                'status' => $lead->status->label(),
                'priority' => $lead->priority->label(),
                'estimated_value' => $lead->estimated_value !== null ? (float) $lead->estimated_value : null,
                'currency' => $lead->currency,
                'expected_close_date' => $lead->expected_close_date?->toDateString(),
                'next_follow_up_at' => $lead->next_follow_up_at?->toDateTimeString(),
                'owner' => $lead->owner?->name,
                'team' => $lead->team?->name,
            ])->all(),
        ];
    }
}
