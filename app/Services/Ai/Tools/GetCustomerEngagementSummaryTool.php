<?php

namespace App\Services\Ai\Tools;

use App\Contracts\Ai\AgentTool;
use App\Models\User;
use App\Services\CostToServe\AccountEconomicsService;
use App\Support\Ai\ToolDefinition;
use Illuminate\Support\Carbon;

/**
 * Phase 12: how much sales engagement (logged activities + sent/received
 * communications) one organization received in a period. This is an
 * *effort* signal, never a cost figure — this application has no cost
 * data (see docs/COST_TO_SERVE.md). Useful alongside
 * get_customer_revenue_summary to spot "rising effort, flat revenue"
 * patterns without ever claiming a cost was incurred.
 */
class GetCustomerEngagementSummaryTool implements AgentTool
{
    public function __construct(private readonly AccountEconomicsService $economics) {}

    public function definition(): ToolDefinition
    {
        return new ToolDefinition(
            name: 'get_customer_engagement_summary',
            description: 'Retrieve how many sales activities and communications were logged for one customer (organization) in a period — a sales-effort signal, not a cost or shipment-volume figure. Identify the customer by organization_id or organization_name. Read-only.',
            parameters: [
                'type' => 'object',
                'properties' => [
                    'organization_id' => ['type' => 'integer', 'description' => 'The organization\'s id, if known.'],
                    'organization_name' => ['type' => 'string', 'description' => 'The organization\'s name, if the id is not known.'],
                    'period_start' => ['type' => 'string', 'description' => 'YYYY-MM-DD. Defaults to the start of the current month.'],
                    'period_end' => ['type' => 'string', 'description' => 'YYYY-MM-DD. Defaults to the end of the current month.'],
                ],
            ],
        );
    }

    public function execute(User $actor, array $arguments): array
    {
        $organization = $this->economics->resolveOrganization($actor, $arguments['organization_id'] ?? null, $arguments['organization_name'] ?? null);

        $start = isset($arguments['period_start']) ? Carbon::parse($arguments['period_start']) : Carbon::now()->startOfMonth();
        $end = isset($arguments['period_end']) ? Carbon::parse($arguments['period_end']) : Carbon::now()->endOfMonth();
        $currency = (string) config('services.cost_to_serve.default_currency', 'USD');

        $snapshot = $this->economics->snapshotForOrganization($actor, $organization, $start, $end, $currency);

        return [
            'organization_id' => $snapshot->organizationId,
            'organization' => $snapshot->organizationName,
            'period_start' => $start->toDateString(),
            'period_end' => $end->toDateString(),
            'activity_count' => $snapshot->activityCount,
            'communication_count' => $snapshot->communicationCount,
            'engagement_count' => $snapshot->engagementCount(),
            'source' => 'Activity and Communication records',
        ];
    }
}
