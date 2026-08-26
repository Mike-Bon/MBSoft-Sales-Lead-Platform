<?php

namespace App\Services\Ai\Tools;

use App\Contracts\Ai\AgentTool;
use App\Models\Team;
use App\Models\User;
use App\Services\PerformanceAuthorizer;
use App\Services\PerformanceService;
use App\Support\Ai\ToolDefinition;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;

/**
 * Reuses PerformanceAuthorizer exactly as TeamPerformanceController does
 * (STEP 21: "the model cannot request another team's opportunities
 * simply by supplying another team ID") — a Team Head/Member requesting
 * a foreign team_id is denied, never silently redirected to their own
 * team, so the denial is visible to the agent (and can be explained to
 * the user) rather than quietly substituting different data.
 */
class GetTeamPerformanceTool implements AgentTool
{
    public function __construct(
        private readonly PerformanceService $performance,
        private readonly PerformanceAuthorizer $authorizer,
    ) {}

    public function definition(): ToolDefinition
    {
        return new ToolDefinition(
            name: 'get_team_performance',
            description: 'Retrieve a team\'s performance (target, actual, achievement, gap, pipeline, coverage) for a given period. Defaults to the authenticated user\'s own team if team_id is omitted. Read-only, authorization-enforced.',
            parameters: [
                'type' => 'object',
                'properties' => [
                    'team_id' => ['type' => 'integer', 'description' => 'The team\'s id. Defaults to the authenticated user\'s own team.'],
                    'period_start' => ['type' => 'string', 'description' => 'YYYY-MM-DD. Defaults to the start of the current month.'],
                    'period_end' => ['type' => 'string', 'description' => 'YYYY-MM-DD. Defaults to the end of the current month.'],
                ],
            ],
        );
    }

    /**
     * @throws AuthorizationException
     */
    public function execute(User $actor, array $arguments): array
    {
        $teamId = $arguments['team_id'] ?? $actor->team_id;

        if ($teamId === null) {
            throw new AuthorizationException('No team was specified and the authenticated user does not belong to one.');
        }

        $team = Team::find($teamId);

        if (! $team) {
            throw new AuthorizationException('That team does not exist.');
        }

        $this->authorizer->authorizeTeam($actor, $team);

        $start = isset($arguments['period_start']) ? Carbon::parse($arguments['period_start']) : Carbon::now()->startOfMonth();
        $end = isset($arguments['period_end']) ? Carbon::parse($arguments['period_end']) : Carbon::now()->endOfMonth();

        $snapshot = $this->performance->forTeam($team, $start, $end);

        return ['team' => $team->name] + GetMyPerformanceTool::snapshotToArray($snapshot);
    }
}
