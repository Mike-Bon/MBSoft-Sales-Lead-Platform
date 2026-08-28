<?php

namespace App\Services\Ai\Tools;

use App\Contracts\Ai\AgentTool;
use App\Models\User;
use App\Services\BusinessDevelopment\LeadIntelligenceService;
use App\Support\Ai\ToolDefinition;

/**
 * Phase 13 (Business Development): open leads that have gone cold — no
 * logged activity and no pending follow-up for at least the configured
 * number of days (config('services.business_development.stale_lead_days')).
 * Read-only, authorization-scoped by LeadIntelligenceService.
 */
class IdentifyStaleLeadsTool implements AgentTool
{
    public function __construct(private readonly LeadIntelligenceService $intelligence) {}

    public function definition(): ToolDefinition
    {
        return new ToolDefinition(
            name: 'identify_stale_leads',
            description: 'List the authenticated user\'s open leads that have gone cold — no activity and no upcoming follow-up for a configured number of days. Read-only. Each result names how long it has been dormant. Never returns leads outside the user\'s authorization scope.',
            parameters: [
                'type' => 'object',
                'properties' => [
                    'team_id' => ['type' => 'integer', 'description' => 'Optional: narrow to one team. Ignored if the user is not authorized to view that team.'],
                    'limit' => ['type' => 'integer', 'description' => 'Maximum leads to return (default and max 25).'],
                ],
            ],
        );
    }

    public function execute(User $actor, array $arguments): array
    {
        return $this->intelligence->staleLeads(
            $actor,
            isset($arguments['team_id']) ? (int) $arguments['team_id'] : null,
            isset($arguments['limit']) ? (int) $arguments['limit'] : null,
        );
    }
}
