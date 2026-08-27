<x-layouts.app>
    <div class="w-full">
        <flux:heading size="xl" level="1">Cost-to-Serve</flux:heading>
        <flux:subheading size="lg" class="mb-6">Revenue and sales-engagement intelligence by account</flux:subheading>

        <flux:callout icon="information-circle" variant="secondary" class="mb-6">
            This application has no cost data (transportation, manpower, handling, or any other operational
            cost) — every figure below is revenue and sales-engagement only. "Average revenue per closed
            deal" is a revenue-concentration measure, not per-unit cost-to-serve or classic ARPU. Ask the
            Cost-to-Serve assistant for a plain-language explanation of any figure and its source.
        </flux:callout>

        <x-performance.period-selector :period="$period" />

        <flux:heading size="lg" class="mb-2">Summary ({{ $currency }})</flux:heading>
        <div class="mb-8 grid grid-cols-2 gap-4 sm:grid-cols-4">
            <x-performance.kpi label="Total Revenue" :value="$currency.' '.number_format($summary['revenue'], 0)" />
            <x-performance.kpi label="Closed Deals" :value="(string) $summary['closed_deals_count']" />
            <x-performance.kpi
                label="Avg Revenue / Closed Deal"
                :undefined="$summary['average_revenue_per_deal'] === null"
                undefined-label="No closed deals"
                :value="$summary['average_revenue_per_deal'] !== null ? $currency.' '.number_format($summary['average_revenue_per_deal'], 0) : null"
            />
            <x-performance.kpi label="Accounts to Review" :value="(string) count($exceptions)" />
        </div>

        <flux:heading size="lg" class="mb-2">Top Accounts by Revenue</flux:heading>
        <flux:table class="mb-8">
            <flux:table.columns>
                <flux:table.column>Organization</flux:table.column>
                <flux:table.column>Revenue</flux:table.column>
                <flux:table.column>Closed Deals</flux:table.column>
                <flux:table.column>Avg Revenue / Deal</flux:table.column>
                <flux:table.column>Engagement</flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @forelse ($topAccounts as $account)
                    <flux:table.row>
                        <flux:table.cell>
                            <a class="underline" href="{{ route('crm.organizations.show', $account->organizationId) }}" wire:navigate>{{ $account->organizationName }}</a>
                        </flux:table.cell>
                        <flux:table.cell>{{ $currency }} {{ number_format($account->revenue, 0) }}</flux:table.cell>
                        <flux:table.cell>{{ $account->closedDealsCount }}</flux:table.cell>
                        <flux:table.cell>{{ $account->averageRevenuePerDeal !== null ? $currency.' '.number_format($account->averageRevenuePerDeal, 0) : '—' }}</flux:table.cell>
                        <flux:table.cell>{{ $account->engagementCount() }}</flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row>
                        <flux:table.cell colspan="5">
                            <p class="py-6 text-center text-sm text-zinc-500 dark:text-zinc-400">No closed revenue in this period.</p>
                        </flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>

        <flux:heading size="lg" class="mb-2">Accounts to Review</flux:heading>
        <p class="mb-4 text-sm text-zinc-500 dark:text-zinc-400">
            Flagged by a defined revenue/engagement pattern (declining revenue, rising engagement without
            rising revenue, or high engagement with zero revenue) — never a "good/bad customer" label, and
            never an automatic action. See docs/COST_TO_SERVE.md for the exact thresholds.
        </p>
        <div class="space-y-3">
            @forelse ($exceptions as $row)
                <div class="rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
                    <div class="flex items-center justify-between">
                        <a class="font-medium underline" href="{{ route('crm.organizations.show', $row['organization']->organizationId) }}" wire:navigate>{{ $row['organization']->organizationName }}</a>
                        <flux:badge size="sm" color="amber">Review</flux:badge>
                    </div>
                    <ul class="mt-2 list-inside list-disc text-sm text-zinc-600 dark:text-zinc-300">
                        @foreach ($row['reasons'] as $reason)
                            <li>{{ $reason }}</li>
                        @endforeach
                    </ul>
                </div>
            @empty
                <p class="text-sm text-zinc-500 dark:text-zinc-400">No accounts currently match a review pattern.</p>
            @endforelse
        </div>
    </div>
</x-layouts.app>
