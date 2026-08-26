{{--
    Shared team performance body — included by both the Team Head
    Dashboard (dashboard/team-head.blade.php) and the /teams/{team}/
    performance drill-down (performance/teams/show.blade.php), so the
    two screens never drift apart (STEP 13: reuse rather than duplicate).

    Expects: $team, $snapshot, $members, $pipelineByStage, $pipelineByOwner,
    $leadStatusCounts, $followUpCounts, $attention — exactly
    TeamDashboardService::build()'s return shape.
--}}
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

    $pipelineOwnerItems = $pipelineByOwner->map(fn ($row) => [
        'label' => $row->owner_name,
        'value' => (float) $row->total,
        'formatted' => number_format($row->total, 0),
    ]);
@endphp

<flux:heading size="lg" class="mb-2">Team Performance</flux:heading>
<div class="mb-4">
    <x-performance.kpi-row :snapshot="$snapshot" />
</div>
<div class="mb-8 max-w-xl">
    <x-performance.target-vs-actual :snapshot="$snapshot" />
</div>

<flux:heading size="lg" class="mb-2">Individual Performance</flux:heading>
<flux:table class="mb-8">
    <flux:table.columns>
        <flux:table.column>Member</flux:table.column>
        <flux:table.column>Target</flux:table.column>
        <flux:table.column>Actual</flux:table.column>
        <flux:table.column>Achievement</flux:table.column>
        <flux:table.column>Gap</flux:table.column>
        <flux:table.column>Pipeline</flux:table.column>
        <flux:table.column>Signal</flux:table.column>
    </flux:table.columns>
    <flux:table.rows>
        @forelse ($members as $row)
            @php($memberSnapshot = $row['snapshot'])
            <flux:table.row>
                <flux:table.cell>
                    <a class="underline" href="{{ route('performance.individual', $row['user']) }}" wire:navigate>{{ $row['user']->name }}</a>
                </flux:table.cell>
                <flux:table.cell>{{ $memberSnapshot->hasTarget ? $memberSnapshot->currency.' '.number_format($memberSnapshot->target, 0) : '—' }}</flux:table.cell>
                <flux:table.cell>{{ $memberSnapshot->currency }} {{ number_format($memberSnapshot->actual, 0) }}</flux:table.cell>
                <flux:table.cell>{{ $memberSnapshot->achievementPercent !== null ? number_format($memberSnapshot->achievementPercent, 1).'%' : '—' }}</flux:table.cell>
                <flux:table.cell>{{ $memberSnapshot->hasTarget ? ($memberSnapshot->gap < 0 ? '+' : '').$memberSnapshot->currency.' '.number_format(abs($memberSnapshot->gap), 0) : '—' }}</flux:table.cell>
                <flux:table.cell>{{ $memberSnapshot->currency }} {{ number_format($memberSnapshot->pipeline, 0) }}</flux:table.cell>
                <flux:table.cell><x-performance.signal-badge :signal="$memberSnapshot->managementSignal()" /></flux:table.cell>
            </flux:table.row>
        @empty
            <flux:table.row><flux:table.cell colspan="7">No team members yet.</flux:table.cell></flux:table.row>
        @endforelse
    </flux:table.rows>
</flux:table>

<flux:heading size="lg" class="mb-2">Team Pipeline</flux:heading>
<div class="mb-8 grid grid-cols-1 gap-6 sm:grid-cols-2">
    <div>
        <flux:subheading class="mb-2">By Stage</flux:subheading>
        <x-performance.bar-list :items="$pipelineStageItems" empty-message="No open pipeline." />
    </div>
    <div>
        <flux:subheading class="mb-2">By Salesperson</flux:subheading>
        <x-performance.bar-list :items="$pipelineOwnerItems" empty-message="No open pipeline." />
    </div>
</div>

<flux:heading size="lg" class="mb-2">Team Leads</flux:heading>
<div class="mb-8 max-w-xl">
    <x-performance.bar-list :items="$leadStatusItems" empty-message="No leads yet." />
</div>
<div class="mb-8">
    <flux:button size="sm" href="{{ route('crm.leads.index', ['team_id' => $team->id]) }}" wire:navigate>View all team leads</flux:button>
</div>

<flux:heading size="lg" class="mb-2">Follow-ups</flux:heading>
<div class="mb-8 grid grid-cols-2 gap-4 sm:grid-cols-4">
    <x-performance.kpi label="Overdue" :value="$followUpCounts['overdue']" />
    <x-performance.kpi label="Due Today" :value="$followUpCounts['due_today']" />
    <x-performance.kpi label="Upcoming" :value="$followUpCounts['upcoming']" />
    <x-performance.kpi label="No Follow-up Set" :value="$followUpCounts['not_set']" />
</div>

@isset($communicationMetrics)
    {{-- Phase 6, STEP 26 --}}
    <flux:heading size="lg" class="mb-2">Communications</flux:heading>
    <div class="mb-8 grid grid-cols-2 gap-4 sm:grid-cols-4">
        <x-performance.kpi label="Emails Sent" :value="$communicationMetrics['emails_sent']" />
        <x-performance.kpi label="WhatsApp Sent" :value="$communicationMetrics['whatsapp_sent']" />
        <x-performance.kpi label="Total Communications" :value="$communicationMetrics['total']" />
        <x-performance.kpi label="Failed" :value="$communicationMetrics['failed']" />
    </div>
@endisset

<flux:heading size="lg" class="mb-2">Needs Attention</flux:heading>
<div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
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
