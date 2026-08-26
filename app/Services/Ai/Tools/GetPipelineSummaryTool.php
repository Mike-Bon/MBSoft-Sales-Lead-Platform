<?php

namespace App\Services\Ai\Tools;

use App\Contracts\Ai\AgentTool;
use App\Enums\OpportunityStage;
use App\Models\Opportunity;
use App\Models\Team;
use App\Models\User;
use App\Services\Dashboard\CrmMetricsService;
use App\Services\PerformanceAuthorizer;
use App\Support\Ai\ToolDefinition;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;

/**
 * Reuses CrmMetricsService::pipelineByStage() — the exact same
 * calculation the dashboards use — over an authorization-scoped
 * Opportunity query. No pipeline arithmetic happens in this tool.
 */
class GetPipelineSummaryTool implements AgentTool
{
    public function __construct(
        private readonly CrmMetricsService $metrics,
        private readonly PerformanceAuthorizer $authorizer,
    ) {}

    public function definition(): ToolDefinition
    {
        return new ToolDefinition(
            name: 'get_pipeline_summary',
            description: 'Retrieve open pipeline value broken down by stage. scope="mine" (default) is the authenticated user\'s own open opportunities; scope="team" is their own team (Manager may also pass team_id for any team); scope="organisation" is Manager-only. Read-only, authorization-enforced.',
            parameters: [
                'type' => 'object',
                'properties' => [
                    'scope' => ['type' => 'string', 'enum' => ['mine', 'team', 'organisation'], 'description' => 'Defaults to "mine".'],
                    'team_id' => ['type' => 'integer', 'description' => 'Only used when scope="team". Defaults to the authenticated user\'s own team.'],
                ],
            ],
        );
    }

    /**
     * @throws AuthorizationException
     */
    public function execute(User $actor, array $arguments): array
    {
        $scope = $arguments['scope'] ?? 'mine';

        $opportunities = match ($scope) {
            'organisation' => $this->organisationScope($actor),
            'team' => $this->teamScope($actor, $arguments['team_id'] ?? $actor->team_id),
            default => Opportunity::query()->where('owner_id', $actor->id),
        };

        $byStage = $this->metrics->pipelineByStage($opportunities);

        return [
            'scope' => $scope,
            'pipeline_by_stage' => collect($byStage)->mapWithKeys(fn ($value, $stage) => [OpportunityStage::from($stage)->label() => $value])->all(),
            'total_open_pipeline' => array_sum($byStage),
        ];
    }

    private function organisationScope(User $actor): Builder
    {
        $this->authorizer->authorizeOrganisation($actor);

        return Opportunity::query();
    }

    private function teamScope(User $actor, ?int $teamId): Builder
    {
        if ($teamId === null) {
            throw new AuthorizationException('No team was specified and the authenticated user does not belong to one.');
        }

        $team = Team::find($teamId);

        if (! $team) {
            throw new AuthorizationException('That team does not exist.');
        }

        $this->authorizer->authorizeTeam($actor, $team);

        return Opportunity::query()->where('opportunities.team_id', $team->id);
    }
}
