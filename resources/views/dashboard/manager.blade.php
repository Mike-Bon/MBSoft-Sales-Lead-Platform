@php
    use App\Enums\LeadStatus;
    use App\Enums\OpportunityStage;

    $leadStatusItems = collect($leadStatusCounts)->map(fn ($count, $status) => [
        'label' => LeadStatus::from($status)->label(),
        'value' => (float) $count,
        'formatted' => (string) $count,
    ])->values();

    $pipelineStageItems = collect($pipelineByStage)->map(fn ($sum, $stage) => [
        'label' => OpportunityStage::from($stage)->label(),
        'value' => (float) $sum,
        'formatted' => number_format($sum, 0),
    ])->values();

    $totalPipeline = collect($pipelineByStage)->sum();

    $sort = request('sort', 'name');
    $dir = request('dir', 'asc');
    $sortLink = fn (string $field) => request()->fullUrlWithQuery([
        'sort' => $field,
        'dir' => ($sort === $field && $dir === 'asc') ? 'desc' : 'asc',
    ]);
@endphp
<x-layouts.app>
    <div class="w-full">
        <flux:heading size="xl" level="1">Manager Dashboard</flux:heading>
        <flux:subheading size="lg" class="mb-6">Organisation-wide performance overview</flux:subheading>

        <x-performance.period-selector :period="$period" />

        {{-- A. Organisation performance — the primary "how am I doing"
             answer leads, ahead of AI Insights (Phase 11A: attention
             items belong after the headline numbers, not before). --}}
        <flux:heading size="lg" class="mb-2">Organisation Performance</flux:heading>
        <div class="mb-4">
            <x-performance.kpi-row :snapshot="$organisation" />
        </div>
        <div class="mb-8 max-w-xl">
            <x-performance.target-vs-actual :snapshot="$organisation" />
        </div>

        <x-ai.insights-card :insights="$aiInsights" />

        {{-- B. Team performance --}}
        <div class="mb-2 flex items-center justify-between">
            <flux:heading size="lg">Team Performance</flux:heading>
        </div>
        <flux:table class="mb-8">
            <flux:table.columns>
                <flux:table.column><a href="{{ $sortLink('name') }}">Team</a></flux:table.column>
                <flux:table.column><a href="{{ $sortLink('target') }}">Target</a></flux:table.column>
                <flux:table.column><a href="{{ $sortLink('actual') }}">Actual</a></flux:table.column>
                <flux:table.column><a href="{{ $sortLink('achievement') }}">Achievement</a></flux:table.column>
                <flux:table.column><a href="{{ $sortLink('gap') }}">Gap</a></flux:table.column>
                <flux:table.column><a href="{{ $sortLink('pipeline') }}">Pipeline</a></flux:table.column>
                <flux:table.column>Coverage</flux:table.column>
                <flux:table.column>Signal</flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @forelse ($teams as $row)
                    @php($snapshot = $row['snapshot'])
                    <flux:table.row>
                        <flux:table.cell>
                            <a class="underline" href="{{ route('performance.teams.show', $row['team']) }}" wire:navigate>{{ $row['team']->name }}</a>
                        </flux:table.cell>
                        <flux:table.cell>{{ $snapshot->hasTarget ? $snapshot->currency.' '.number_format($snapshot->target, 0) : '—' }}</flux:table.cell>
                        <flux:table.cell>{{ $snapshot->currency }} {{ number_format($snapshot->actual, 0) }}</flux:table.cell>
                        <flux:table.cell>{{ $snapshot->achievementPercent !== null ? number_format($snapshot->achievementPercent, 1).'%' : '—' }}</flux:table.cell>
                        <flux:table.cell>{{ $snapshot->hasTarget ? ($snapshot->gap < 0 ? '+' : '').$snapshot->currency.' '.number_format(abs($snapshot->gap), 0) : '—' }}</flux:table.cell>
                        <flux:table.cell>{{ $snapshot->currency }} {{ number_format($snapshot->pipeline, 0) }}</flux:table.cell>
                        <flux:table.cell>{{ $snapshot->pipelineCoverage !== null ? number_format($snapshot->pipelineCoverage, 2).'×' : '—' }}</flux:table.cell>
                        <flux:table.cell><x-performance.signal-badge :signal="$snapshot->managementSignal()" /></flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row><flux:table.cell colspan="8">No teams found.</flux:table.cell></flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>

        {{-- C. Performance trend --}}
        <flux:heading size="lg" class="mb-2">Performance Trend</flux:heading>
        <div class="mb-8">
            @if (count($trend) >= 2)
                <x-performance.bar-list
                    :items="collect($trend)->map(fn ($point) => [
                        'label' => $point['target']->period_start->format('M Y'),
                        'value' => $point['snapshot']->actual,
                        'formatted' => $point['snapshot']->currency.' '.number_format($point['snapshot']->actual, 0).' ('.($point['snapshot']->achievementPercent !== null ? number_format($point['snapshot']->achievementPercent, 0).'%' : '—').')',
                    ])"
                />
            @else
                <flux:callout icon="chart-bar" variant="secondary">
                    Not enough historical data yet to show a trend. This will populate as more monthly Manager targets are recorded.
                </flux:callout>
            @endif
        </div>

        {{-- D. Sales pipeline --}}
        <flux:heading size="lg" class="mb-2">Sales Pipeline</flux:heading>
        <flux:subheading class="mb-2">Total open pipeline: {{ number_format($totalPipeline, 0) }} — distinct from Actual above, which counts only recognized Closed Won sales.</flux:subheading>
        <div class="mb-8 max-w-xl">
            <x-performance.bar-list :items="$pipelineStageItems" empty-message="No open pipeline." />
        </div>

        {{-- E. Lead overview --}}
        <flux:heading size="lg" class="mb-2">Lead Overview</flux:heading>
        <div class="mb-8 max-w-xl">
            <x-performance.bar-list :items="$leadStatusItems" empty-message="No leads yet." />
        </div>

        {{-- F. Follow-up overview --}}
        <flux:heading size="lg" class="mb-2">Follow-up Overview</flux:heading>
        <div class="mb-8 grid grid-cols-2 gap-4 sm:grid-cols-4">
            <x-performance.kpi label="Overdue" :value="$followUpCounts['overdue']" />
            <x-performance.kpi label="Due Today" :value="$followUpCounts['due_today']" />
            <x-performance.kpi label="Upcoming" :value="$followUpCounts['upcoming']" />
            <x-performance.kpi label="No Follow-up Set" :value="$followUpCounts['not_set']" />
        </div>

        {{-- G. Communications overview (Phase 6, STEP 26) --}}
        <flux:heading size="lg" class="mb-2">Communications ({{ $period->start->format('M j') }}–{{ $period->end->format('M j, Y') }})</flux:heading>
        <div class="mb-8 grid grid-cols-2 gap-4 sm:grid-cols-4">
            <x-performance.kpi label="Emails Sent" :value="$communicationMetrics['emails_sent']" />
            <x-performance.kpi label="WhatsApp Sent" :value="$communicationMetrics['whatsapp_sent']" />
            <x-performance.kpi label="Total Communications" :value="$communicationMetrics['total']" />
            <x-performance.kpi label="Failed" :value="$communicationMetrics['failed']" />
        </div>

        {{-- Attention areas. "Teams Behind or At Risk" was removed from
             here (Phase 11A): the Team Performance table above already
             shows the identical Signal per team — repeating it a
             second time added no information, only noise. --}}
        <flux:heading size="lg" class="mb-2">Needs Attention</flux:heading>
        <div class="mb-8 grid grid-cols-1 gap-6 lg:grid-cols-3">
            <div>
                <flux:subheading class="mb-2">Overdue Follow-ups</flux:subheading>
                <x-performance.attention-leads :leads="$attention['overdueLeads']" show-owner empty-message="No overdue follow-ups." />
            </div>
            <div>
                <flux:subheading class="mb-2">High Priority Leads</flux:subheading>
                <x-performance.attention-leads :leads="$attention['highPriorityLeads']" show-owner empty-message="No open high-priority leads." />
            </div>
            <div>
                <flux:subheading class="mb-2">Opportunities Closing Soon</flux:subheading>
                <x-performance.attention-opportunities :opportunities="$attention['closingSoonOpportunities']" show-owner empty-message="Nothing closing in the next two weeks." />
            </div>
        </div>
    </div>
</x-layouts.app>
