<?php

namespace App\Services\Ai\Tools;

use App\Contracts\Ai\AgentTool;
use App\Models\User;
use App\Services\CostToServe\AccountEconomicsService;
use App\Support\Ai\ToolDefinition;
use Illuminate\Support\Carbon;

/**
 * Phase 12: revenue realized (Closed Won) from one organization for one
 * period, plus the approved "average revenue per closed deal" proxy
 * metric. Deliberately does NOT return anything named "cost",
 * "contribution", or "ARPU" — see docs/COST_TO_SERVE.md. Every result
 * carries `data_gap` explaining what a true Cost-to-Serve calculation
 * would additionally require, so the model never has to guess.
 */
class GetCustomerRevenueSummaryTool implements AgentTool
{
    public function __construct(private readonly AccountEconomicsService $economics) {}

    public function definition(): ToolDefinition
    {
        return new ToolDefinition(
            name: 'get_customer_revenue_summary',
            description: 'Retrieve one customer\'s (organization\'s) realized revenue (Closed Won opportunity value), closed deal count, and average revenue per closed deal for a period. Identify the customer by organization_id or organization_name. Read-only. Contains no cost data — this application has none; see the returned data_gap field.',
            parameters: [
                'type' => 'object',
                'properties' => [
                    'organization_id' => ['type' => 'integer', 'description' => 'The organization\'s id, if known.'],
                    'organization_name' => ['type' => 'string', 'description' => 'The organization\'s name, if the id is not known.'],
                    'period_start' => ['type' => 'string', 'description' => 'YYYY-MM-DD. Defaults to the start of the current month.'],
                    'period_end' => ['type' => 'string', 'description' => 'YYYY-MM-DD. Defaults to the end of the current month.'],
                    'currency' => ['type' => 'string', 'description' => 'Three-letter currency code. Defaults to the application\'s configured default currency. Revenue in other currencies is never mixed into this figure.'],
                ],
            ],
        );
    }

    public function execute(User $actor, array $arguments): array
    {
        $organization = $this->economics->resolveOrganization($actor, $arguments['organization_id'] ?? null, $arguments['organization_name'] ?? null);

        $start = isset($arguments['period_start']) ? Carbon::parse($arguments['period_start']) : Carbon::now()->startOfMonth();
        $end = isset($arguments['period_end']) ? Carbon::parse($arguments['period_end']) : Carbon::now()->endOfMonth();
        $currency = $arguments['currency'] ?? (string) config('services.cost_to_serve.default_currency', 'USD');

        $snapshot = $this->economics->snapshotForOrganization($actor, $organization, $start, $end, $currency);

        return [
            ...$snapshot->toArray(),
            'period_start' => $start->toDateString(),
            'period_end' => $end->toDateString(),
            'source' => 'Closed Won opportunity records',
            'data_gap' => 'No cost data exists in this application (transportation, manpower, handling, or any other operational cost). This is revenue only, never contribution or cost-to-serve.',
        ];
    }
}
