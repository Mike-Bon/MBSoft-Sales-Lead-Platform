<?php

namespace App\Services\Ai\Tools;

use App\Contracts\Ai\AgentTool;
use App\Models\User;
use App\Services\BusinessDevelopment\LeadIntelligenceService;
use App\Support\Ai\ToolDefinition;

/**
 * Phase 13 (Business Development): open opportunities that appear
 * stalled — no logged activity for the configured number of days, or
 * already past their expected close date. Every flagged opportunity
 * names exactly why (spec §16). Read-only, authorization-scoped.
 */
class IdentifyAtRiskOpportunitiesTool implements AgentTool
{
    public function __construct(private readonly LeadIntelligenceService $intelligence) {}

    public function definition(): ToolDefinition
    {
        return new ToolDefinition(
            name: 'identify_at_risk_opportunities',
            description: 'List the authenticated user\'s open opportunities that look at risk — stalled with no recent activity, or past their expected close date. Read-only. Each result names the reason(s) it was flagged. Never returns opportunities outside the user\'s authorization scope.',
            parameters: [
                'type' => 'object',
                'properties' => [
                    'team_id' => ['type' => 'integer', 'description' => 'Optional: narrow to one team. Ignored if the user is not authorized to view that team.'],
                    'limit' => ['type' => 'integer', 'description' => 'Maximum opportunities to return (default and max 25).'],
                ],
            ],
        );
    }

    public function execute(User $actor, array $arguments): array
    {
        return $this->intelligence->atRiskOpportunities(
            $actor,
            isset($arguments['team_id']) ? (int) $arguments['team_id'] : null,
            isset($arguments['limit']) ? (int) $arguments['limit'] : null,
        );
    }
}
