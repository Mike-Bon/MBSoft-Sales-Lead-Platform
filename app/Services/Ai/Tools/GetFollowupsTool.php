<?php

namespace App\Services\Ai\Tools;

use App\Contracts\Ai\AgentTool;
use App\Enums\LeadStatus;
use App\Models\Lead;
use App\Models\Team;
use App\Models\User;
use App\Services\Dashboard\CrmMetricsService;
use App\Services\PerformanceAuthorizer;
use App\Support\Ai\ToolDefinition;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * STEP 15: follow-up buckets, using the exact boundary definitions
 * CrmMetricsService::followUpCounts() already established for the
 * dashboards (overdue = before today; due_today = today; upcoming =
 * after today; not_set = no next_follow_up_at) — never a new,
 * independently-invented definition of "overdue".
 */
class GetFollowupsTool implements AgentTool
{
    public function __construct(
        private readonly CrmMetricsService $metrics,
        private readonly PerformanceAuthorizer $authorizer,
    ) {}

    public function definition(): ToolDefinition
    {
        return new ToolDefinition(
            name: 'get_followups',
            description: 'Retrieve leads by follow-up bucket (overdue, due today, upcoming, or with no follow-up date set). scope="mine" (default) is the authenticated user\'s own leads; scope="team" is their own team (Manager may also pass team_id); scope="organisation" is Manager-only. Read-only, authorization-enforced.',
            parameters: [
                'type' => 'object',
                'properties' => [
                    'bucket' => ['type' => 'string', 'enum' => ['overdue', 'due_today', 'upcoming', 'not_set'], 'description' => 'Which follow-up bucket to retrieve.'],
                    'scope' => ['type' => 'string', 'enum' => ['mine', 'team', 'organisation'], 'description' => 'Defaults to "mine".'],
                    'team_id' => ['type' => 'integer', 'description' => 'Only used when scope="team". Defaults to the authenticated user\'s own team.'],
                    'limit' => ['type' => 'integer', 'description' => 'Maximum results to return (default 10, max 25).'],
                ],
                'required' => ['bucket'],
            ],
        );
    }

    /**
     * @throws AuthorizationException
     */
    public function execute(User $actor, array $arguments): array
    {
        $scope = $arguments['scope'] ?? 'mine';

        $leads = match ($scope) {
            'organisation' => $this->organisationScope($actor),
            'team' => $this->teamScope($actor, $arguments['team_id'] ?? $actor->team_id),
            default => Lead::query()->where('owner_id', $actor->id),
        };

        $limit = min((int) ($arguments['limit'] ?? 10), 25);
        $bucket = $arguments['bucket'];

        $results = match ($bucket) {
            'overdue' => $this->metrics->overdueLeads($leads, $limit),
            'due_today' => $this->dueTodayLeads($leads, $limit),
            'upcoming' => $this->upcomingLeads($leads, $limit),
            'not_set' => $this->notSetLeads($leads, $limit),
            default => throw new \InvalidArgumentException('Unknown follow-up bucket.'),
        };

        return [
            'bucket' => $bucket,
            'scope' => $scope,
            'count' => $results->count(),
            'leads' => $results->map(fn (Lead $lead) => [
                'id' => $lead->id,
                'organization' => $lead->organization?->name,
                'contact' => $lead->contact?->fullName(),
                'status' => $lead->status->label(),
                'next_follow_up_at' => $lead->next_follow_up_at?->toDateTimeString(),
                'owner' => $lead->owner?->name,
            ])->all(),
        ];
    }

    private function baseQuery(Builder $leads): Builder
    {
        return (clone $leads)
            ->with(['organization', 'contact', 'owner'])
            ->whereNotIn('status', [LeadStatus::Disqualified->value, LeadStatus::Converted->value]);
    }

    private function dueTodayLeads(Builder $leads, int $limit): Collection
    {
        $now = Carbon::now();

        return $this->baseQuery($leads)
            ->whereBetween('next_follow_up_at', [$now->copy()->startOfDay(), $now->copy()->endOfDay()])
            ->orderBy('next_follow_up_at')
            ->limit($limit)
            ->get();
    }

    private function upcomingLeads(Builder $leads, int $limit): Collection
    {
        return $this->baseQuery($leads)
            ->where('next_follow_up_at', '>', Carbon::now()->endOfDay())
            ->orderBy('next_follow_up_at')
            ->limit($limit)
            ->get();
    }

    private function notSetLeads(Builder $leads, int $limit): Collection
    {
        return $this->baseQuery($leads)
            ->whereNull('next_follow_up_at')
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();
    }

    /**
     * @throws AuthorizationException
     */
    private function organisationScope(User $actor): Builder
    {
        $this->authorizer->authorizeOrganisation($actor);

        return Lead::query();
    }

    /**
     * @throws AuthorizationException
     */
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

        return Lead::query()->where('leads.team_id', $team->id);
    }
}
