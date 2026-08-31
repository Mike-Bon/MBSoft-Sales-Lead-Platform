<?php

namespace App\Services\Ai\Tools;

use App\Contracts\Ai\AgentTool;
use App\Models\User;
use App\Services\CostToServe\AccountEconomicsService;
use App\Support\Ai\ToolDefinition;
use App\Support\Money;
use Illuminate\Support\Carbon;

/**
 * Phase 12 STEP 10: period-over-period comparison for one customer —
 * revenue, closed deal count, engagement, and average revenue per
 * closed deal, each reported via MetricChange (which handles a
 * zero/near-zero previous value explicitly rather than an
 * infinite/misleading percentage). If no previous period is given, it
 * defaults to the immediately preceding window of the same length as
 * the current one.
 */
class CompareAccountPeriodTool implements AgentTool
{
    public function __construct(private readonly AccountEconomicsService $economics) {}

    public function definition(): ToolDefinition
    {
        return new ToolDefinition(
            name: 'compare_account_period',
            description: 'Compare one customer\'s (organization\'s) revenue, closed deal count, and sales engagement between two periods (e.g. this month vs. last month). Identify the customer by organization_id or organization_name. Read-only.',
            parameters: [
                'type' => 'object',
                'properties' => [
                    'organization_id' => ['type' => 'integer', 'description' => 'The organization\'s id, if known.'],
                    'organization_name' => ['type' => 'string', 'description' => 'The organization\'s name, if the id is not known.'],
                    'period_start' => ['type' => 'string', 'description' => 'YYYY-MM-DD, the current period\'s start. Defaults to the start of the current month.'],
                    'period_end' => ['type' => 'string', 'description' => 'YYYY-MM-DD, the current period\'s end. Defaults to the end of the current month.'],
                    'previous_period_start' => ['type' => 'string', 'description' => 'YYYY-MM-DD. Defaults to the equivalent-length period immediately before the current one.'],
                    'previous_period_end' => ['type' => 'string', 'description' => 'YYYY-MM-DD. Defaults to the equivalent-length period immediately before the current one.'],
                    'currency' => ['type' => 'string', 'description' => 'Three-letter currency code. Defaults to the application\'s configured default currency.'],
                ],
            ],
        );
    }

    public function execute(User $actor, array $arguments): array
    {
        $organization = $this->economics->resolveOrganization($actor, $arguments['organization_id'] ?? null, $arguments['organization_name'] ?? null);

        $currentStart = isset($arguments['period_start']) ? Carbon::parse($arguments['period_start']) : Carbon::now()->startOfMonth();
        $currentEnd = isset($arguments['period_end']) ? Carbon::parse($arguments['period_end']) : Carbon::now()->endOfMonth();

        if (isset($arguments['previous_period_start'], $arguments['previous_period_end'])) {
            $previousStart = Carbon::parse($arguments['previous_period_start']);
            $previousEnd = Carbon::parse($arguments['previous_period_end']);
        } else {
            $lengthInDays = $currentStart->diffInDays($currentEnd);
            $previousEnd = $currentStart->copy()->subDay();
            $previousStart = $previousEnd->copy()->subDays($lengthInDays);
        }

        $currency = $arguments['currency'] ?? (string) config('services.cost_to_serve.default_currency', Money::defaultCurrency());

        $comparison = $this->economics->comparePeriods($actor, $organization, $currentStart, $currentEnd, $previousStart, $previousEnd, $currency);

        return [
            'organization_id' => $organization->id,
            'organization' => $organization->name,
            'current_period' => ['start' => $currentStart->toDateString(), 'end' => $currentEnd->toDateString()],
            'previous_period' => ['start' => $previousStart->toDateString(), 'end' => $previousEnd->toDateString()],
            'revenue' => $comparison['revenue']->toArray(),
            'closed_deals' => $comparison['closed_deals']->toArray(),
            'engagement' => $comparison['engagement']->toArray(),
            'average_revenue_per_closed_deal' => $comparison['average_revenue_per_deal']->toArray(),
            'source' => 'Closed Won opportunity, Activity, and Communication records',
            'data_gap' => 'No cost data exists in this application — cost change and cost-to-serve change cannot be reported.',
        ];
    }
}
