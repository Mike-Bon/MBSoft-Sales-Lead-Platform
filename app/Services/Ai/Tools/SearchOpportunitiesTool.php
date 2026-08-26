<?php

namespace App\Services\Ai\Tools;

use App\Contracts\Ai\AgentTool;
use App\Enums\OpportunityStage;
use App\Http\Controllers\Concerns\ScopesCrmQueries;
use App\Models\Opportunity;
use App\Models\User;
use App\Support\Ai\ToolDefinition;

/**
 * Mirrors OpportunityController::index's filters, plus a value range
 * (STEP 10 explicitly asks for one; applied as an additional narrowing
 * filter on top of the same authorized, scoped query — never a
 * standalone unscoped query).
 */
class SearchOpportunitiesTool implements AgentTool
{
    use ScopesCrmQueries;

    public function definition(): ToolDefinition
    {
        return new ToolDefinition(
            name: 'search_opportunities',
            description: 'Search the authenticated user\'s own authorized opportunities using supported business filters. Read-only. Never returns opportunities outside the user\'s authorization scope, regardless of any team_id/owner_id supplied.',
            parameters: [
                'type' => 'object',
                'properties' => [
                    'stage' => ['type' => 'string', 'enum' => array_column(OpportunityStage::cases(), 'value'), 'description' => 'Filter by opportunity stage.'],
                    'owner_id' => ['type' => 'integer', 'description' => 'Filter to a specific owner. Ignored if the requesting user is not authorized to view that owner\'s opportunities.'],
                    'team_id' => ['type' => 'integer', 'description' => 'Filter to a specific team. Ignored if the requesting user is not authorized to view that team\'s opportunities.'],
                    'value_min' => ['type' => 'number', 'description' => 'Minimum opportunity value.'],
                    'value_max' => ['type' => 'number', 'description' => 'Maximum opportunity value.'],
                    'closing_from' => ['type' => 'string', 'description' => 'Expected close date lower bound, YYYY-MM-DD.'],
                    'closing_to' => ['type' => 'string', 'description' => 'Expected close date upper bound, YYYY-MM-DD.'],
                    'limit' => ['type' => 'integer', 'description' => 'Maximum results to return (default 10, max 25).'],
                ],
            ],
        );
    }

    public function execute(User $actor, array $arguments): array
    {
        $query = $this->scopeToUser(Opportunity::query()->with(['organization', 'contact', 'lead', 'owner', 'team']), $actor);

        if ($stage = $arguments['stage'] ?? null) {
            $query->where('stage', $stage);
        }

        if ($ownerId = $arguments['owner_id'] ?? null) {
            $query->where('owner_id', $ownerId);
        }

        if ($teamId = $arguments['team_id'] ?? null) {
            $query->where('opportunities.team_id', $teamId);
        }

        if (isset($arguments['value_min'])) {
            $query->where('value', '>=', $arguments['value_min']);
        }

        if (isset($arguments['value_max'])) {
            $query->where('value', '<=', $arguments['value_max']);
        }

        if ($closingFrom = $arguments['closing_from'] ?? null) {
            $query->whereDate('expected_close_date', '>=', $closingFrom);
        }

        if ($closingTo = $arguments['closing_to'] ?? null) {
            $query->whereDate('expected_close_date', '<=', $closingTo);
        }

        $limit = min((int) ($arguments['limit'] ?? 10), 25);

        $opportunities = $query->orderByDesc('created_at')->limit($limit)->get();

        return [
            'count' => $opportunities->count(),
            'opportunities' => $opportunities->map(fn (Opportunity $opportunity) => [
                'id' => $opportunity->id,
                'name' => $opportunity->name,
                'organization' => $opportunity->organization?->name,
                'contact' => $opportunity->contact?->fullName(),
                'stage' => $opportunity->stage->label(),
                'value' => $opportunity->value !== null ? (float) $opportunity->value : null,
                'currency' => $opportunity->currency,
                'probability' => $opportunity->probability,
                'expected_close_date' => $opportunity->expected_close_date?->toDateString(),
                'owner' => $opportunity->owner?->name,
                'team' => $opportunity->team?->name,
            ])->all(),
        ];
    }
}
