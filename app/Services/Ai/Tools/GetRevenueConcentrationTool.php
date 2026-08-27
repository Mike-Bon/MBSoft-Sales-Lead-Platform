<?php

namespace App\Services\Ai\Tools;

use App\Contracts\Ai\AgentTool;
use App\Models\User;
use App\Services\CostToServe\AccountEconomicsService;
use App\Support\Ai\ToolDefinition;
use Illuminate\Support\Carbon;

/**
 * Phase 12: "top accounts" by realized revenue — the closest honest
 * equivalent this data supports to "top accounts by contribution"
 * (contribution itself cannot be computed; see
 * docs/COST_TO_SERVE.md). Manager sees the whole organisation
 * (optionally filtered to one team); Team Head is always scoped to
 * their own team, regardless of what team_id is requested.
 */
class GetRevenueConcentrationTool implements AgentTool
{
    public function __construct(private readonly AccountEconomicsService $economics) {}

    public function definition(): ToolDefinition
    {
        return new ToolDefinition(
            name: 'get_revenue_concentration',
            description: 'Retrieve the top customers (organizations) ranked by realized revenue (Closed Won opportunity value) for a period, within the authenticated user\'s authorized scope. Read-only. Contains no cost/contribution data.',
            parameters: [
                'type' => 'object',
                'properties' => [
                    'period_start' => ['type' => 'string', 'description' => 'YYYY-MM-DD. Defaults to the start of the current month.'],
                    'period_end' => ['type' => 'string', 'description' => 'YYYY-MM-DD. Defaults to the end of the current month.'],
                    'currency' => ['type' => 'string', 'description' => 'Three-letter currency code. Defaults to the application\'s configured default currency.'],
                    'team_id' => ['type' => 'integer', 'description' => 'Optionally restrict to one team. A Team Head\'s own team is always enforced regardless of this value.'],
                    'limit' => ['type' => 'integer', 'description' => 'Maximum accounts to return (default and max from application configuration).'],
                ],
            ],
        );
    }

    public function execute(User $actor, array $arguments): array
    {
        $start = isset($arguments['period_start']) ? Carbon::parse($arguments['period_start']) : Carbon::now()->startOfMonth();
        $end = isset($arguments['period_end']) ? Carbon::parse($arguments['period_end']) : Carbon::now()->endOfMonth();
        $currency = $arguments['currency'] ?? (string) config('services.cost_to_serve.default_currency', 'USD');
        $maxAccounts = (int) config('services.cost_to_serve.max_accounts_per_query', 20);
        $limit = min((int) ($arguments['limit'] ?? $maxAccounts), $maxAccounts);

        $accounts = $this->economics->topAccountsByRevenue($actor, $start, $end, $currency, $limit, $arguments['team_id'] ?? null);

        return [
            'period_start' => $start->toDateString(),
            'period_end' => $end->toDateString(),
            'currency' => $currency,
            'count' => $accounts->count(),
            'accounts' => $accounts->map->toArray()->all(),
            'source' => 'Closed Won opportunity, Activity, and Communication records',
            'data_gap' => 'Ranked by revenue only — no cost data exists in this application, so this is not a contribution or cost-to-serve ranking.',
        ];
    }
}
