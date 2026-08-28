<?php

namespace App\Services\Ai\Tools;

use App\Contracts\Ai\AgentTool;
use App\Models\User;
use App\Services\BusinessDevelopment\LeadIntelligenceService;
use App\Support\Ai\ToolDefinition;

/**
 * Phase 13 (Business Development): a transparent, explainable lead
 * ranking. Every lead returned carries the exact factors and points
 * that produced its score, plus the deterministic recommended action —
 * never a bare number (spec §13). Read-only; the actual scope is always
 * the authenticated user's own authorized leads (Manager: all; Team
 * Head: own team), re-derived from the actor by LeadIntelligenceService,
 * never from arguments.
 */
class PrioritizeLeadsTool implements AgentTool
{
    public function __construct(private readonly LeadIntelligenceService $intelligence) {}

    public function definition(): ToolDefinition
    {
        return new ToolDefinition(
            name: 'prioritize_leads',
            description: 'Rank the authenticated user\'s own open leads by a transparent, points-based score. Each result lists the factors and points behind its score and a recommended next action. Read-only. Never returns leads outside the user\'s authorization scope, regardless of any team_id supplied.',
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
        return $this->intelligence->prioritizedLeads(
            $actor,
            isset($arguments['team_id']) ? (int) $arguments['team_id'] : null,
            isset($arguments['limit']) ? (int) $arguments['limit'] : null,
        );
    }
}
