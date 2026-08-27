<?php

namespace App\Support\CostToServe;

/**
 * Phase 12: one organization's revenue/engagement picture for one
 * period. Deliberately named "Economics", never "Cost-to-Serve" or
 * "Contribution" — this DTO carries only what the schema actually
 * supports (see docs/COST_TO_SERVE.md's data-availability matrix):
 * revenue realized from Closed Won opportunities, and sales-engagement
 * touch counts. It has no cost, no contribution, and no true
 * per-unit ARPU field, because none of those can be honestly computed
 * from this application's data.
 *
 * `averageRevenuePerDeal` is the one approved proxy metric (STEP —
 * user-approved substitute for classic ARPU): revenue ÷ count of
 * Closed Won deals in the period, null when there were none. It is
 * never called "ARPU" in any user-facing text without the "per closed
 * deal" qualifier, to avoid it being mistaken for true per-unit ARPU.
 */
final readonly class AccountEconomicsSnapshot
{
    public function __construct(
        public int $organizationId,
        public string $organizationName,
        public string $currency,
        public float $revenue,
        public int $closedDealsCount,
        public ?float $averageRevenuePerDeal,
        public int $activityCount,
        public int $communicationCount,
    ) {}

    public function engagementCount(): int
    {
        return $this->activityCount + $this->communicationCount;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'organization_id' => $this->organizationId,
            'organization' => $this->organizationName,
            'currency' => $this->currency,
            'revenue' => round($this->revenue, 2),
            'closed_deals_count' => $this->closedDealsCount,
            'average_revenue_per_closed_deal' => $this->averageRevenuePerDeal !== null ? round($this->averageRevenuePerDeal, 2) : null,
            'activity_count' => $this->activityCount,
            'communication_count' => $this->communicationCount,
            'engagement_count' => $this->engagementCount(),
        ];
    }
}
