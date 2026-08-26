<x-layouts.app>
    <div class="w-full max-w-4xl">
        <flux:heading size="xl" level="1">My Dashboard</flux:heading>
        <flux:subheading size="lg" class="mb-6">
            {{ $user->name }}
            @if ($team)
                &middot; {{ $team->name }}
            @endif
        </flux:subheading>

        <x-performance.period-selector :period="$period" />

        <flux:heading size="lg" class="mb-2">My Performance</flux:heading>
        <div class="mb-4">
            <x-performance.kpi-row :snapshot="$snapshot" />
        </div>
        <div class="mb-8 max-w-xl">
            <x-performance.target-vs-actual :snapshot="$snapshot" />
        </div>

        <div class="mb-8 grid grid-cols-2 gap-4 sm:grid-cols-4">
            <x-performance.kpi label="Open Leads" :value="$openLeadsCount" />
            <x-performance.kpi label="Open Opportunities" :value="$openOpportunitiesCount" />
            <x-performance.kpi label="Overdue Follow-ups" :value="$followUpCounts['overdue']" />
            <x-performance.kpi label="Upcoming Follow-ups" :value="$followUpCounts['upcoming']" />
        </div>

        <div class="mb-8 flex gap-3">
            <flux:button size="sm" href="{{ route('crm.leads.index', ['owner_id' => $user->id]) }}" wire:navigate>My Leads</flux:button>
            <flux:button size="sm" href="{{ route('crm.opportunities.index', ['owner_id' => $user->id]) }}" wire:navigate>My Opportunities</flux:button>
        </div>

        {{-- Phase 6, STEP 26 --}}
        <flux:heading size="lg" class="mb-2">My Communications</flux:heading>
        <div class="mb-8 grid grid-cols-2 gap-4 sm:grid-cols-4">
            <x-performance.kpi label="Emails Sent" :value="$communicationMetrics['emails_sent']" />
            <x-performance.kpi label="WhatsApp Sent" :value="$communicationMetrics['whatsapp_sent']" />
            <x-performance.kpi label="Total Communications" :value="$communicationMetrics['total']" />
            <x-performance.kpi label="Failed" :value="$communicationMetrics['failed']" />
        </div>

        <flux:heading size="lg" class="mb-2">Overdue Follow-ups</flux:heading>
        <div class="mb-8">
            <x-performance.attention-leads :leads="$overdueLeads" empty-message="No overdue follow-ups." />
        </div>

        <flux:heading size="lg" class="mb-2">Upcoming Follow-ups</flux:heading>
        <div>
            <x-performance.attention-leads :leads="$upcomingLeads" empty-message="No upcoming follow-ups scheduled." />
        </div>
    </div>
</x-layouts.app>
