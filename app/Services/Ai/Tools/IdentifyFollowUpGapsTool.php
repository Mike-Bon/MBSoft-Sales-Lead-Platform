<?php

namespace App\Services\Ai\Tools;

use App\Contracts\Ai\AgentTool;
use App\Models\User;
use App\Services\BusinessDevelopment\LeadIntelligenceService;
use App\Support\Ai\ToolDefinition;

/**
 * Phase 13 (Business Development): open leads needing a next action now
 * — follow-up overdue, or no follow-up date set at all. Uses the exact
 * bucket boundaries CrmMetricsService::followUpCounts() established for
 * the dashboards. Read-only, authorization-scoped.
 */
class IdentifyFollowUpGapsTool implements AgentTool
{
    public function __construct(private readonly LeadIntelligenceService $intelligence) {}

    public function definition(): ToolDefinition
    {
        return new ToolDefinition(
            name: 'identify_follow_up_gaps',
            description: 'List the authenticated user\'s open leads with an overdue follow-up or no follow-up date set. Read-only. Each result says whether the follow-up is overdue or missing and a recommended action. Never returns leads outside the user\'s authorization scope.',
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
        return $this->intelligence->followUpGaps(
            $actor,
            isset($arguments['team_id']) ? (int) $arguments['team_id'] : null,
            isset($arguments['limit']) ? (int) $arguments['limit'] : null,
        );
    }
}
