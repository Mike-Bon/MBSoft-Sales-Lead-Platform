<?php

namespace App\Services\Ai\Tools;

use App\Contracts\Ai\AgentTool;
use App\Models\User;
use App\Services\CostToServe\AccountEconomicsService;
use App\Support\Ai\ToolDefinition;
use App\Support\Money;
use Illuminate\Support\Carbon;

/**
 * Phase 12 STEP 11: deterministic, config-driven exception detection —
 * see AccountEconomicsService::identifyExceptions()'s own docblock for
 * the exact three rules and their configured thresholds
 * (config('services.cost_to_serve')). Every result names which rule(s)
 * it tripped and the threshold used (STEP 13), never a bare "this
 * account looks bad."
 */
class IdentifyRevenueExceptionsTool implements AgentTool
{
    public function __construct(private readonly AccountEconomicsService $economics) {}

    public function definition(): ToolDefinition
    {
        return new ToolDefinition(
            name: 'identify_revenue_exceptions',
            description: 'Identify customers (organizations) matching a defined revenue/engagement exception pattern for a period compared with the previous period: revenue declining beyond a configured threshold, engagement rising while revenue does not, or zero revenue with meaningfully high engagement. Read-only. These are revenue/engagement patterns, never cost exceptions — this application has no cost data.',
            parameters: [
                'type' => 'object',
                'properties' => [
                    'period_start' => ['type' => 'string', 'description' => 'YYYY-MM-DD. Defaults to the start of the current month.'],
                    'period_end' => ['type' => 'string', 'description' => 'YYYY-MM-DD. Defaults to the end of the current month.'],
                    'previous_period_start' => ['type' => 'string', 'description' => 'YYYY-MM-DD. Defaults to the equivalent-length period immediately before the current one.'],
                    'previous_period_end' => ['type' => 'string', 'description' => 'YYYY-MM-DD. Defaults to the equivalent-length period immediately before the current one.'],
                    'currency' => ['type' => 'string', 'description' => 'Three-letter currency code. Defaults to the application\'s configured default currency.'],
                    'team_id' => ['type' => 'integer', 'description' => 'Optionally restrict to one team. A Team Head\'s own team is always enforced regardless of this value.'],
                    'limit' => ['type' => 'integer', 'description' => 'Maximum accounts to return (default and max from application configuration).'],
                ],
            ],
        );
    }

    public function execute(User $actor, array $arguments): array
    {
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
        $maxAccounts = (int) config('services.cost_to_serve.max_accounts_per_query', 20);
        $limit = min((int) ($arguments['limit'] ?? $maxAccounts), $maxAccounts);

        $flagged = $this->economics->identifyExceptions(
            $actor,
            $currentStart,
            $currentEnd,
            $previousStart,
            $previousEnd,
            $currency,
            $arguments['team_id'] ?? null,
            $limit,
        );

        return [
            'current_period' => ['start' => $currentStart->toDateString(), 'end' => $currentEnd->toDateString()],
            'previous_period' => ['start' => $previousStart->toDateString(), 'end' => $previousEnd->toDateString()],
            'count' => count($flagged),
            'accounts' => array_map(fn (array $row) => [
                ...$row['organization']->toArray(),
                'reasons' => $row['reasons'],
            ], $flagged),
            'source' => 'Closed Won opportunity, Activity, and Communication records',
        ];
    }
}
