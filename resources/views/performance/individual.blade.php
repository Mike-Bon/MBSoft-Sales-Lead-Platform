<x-layouts.app>
    <div class="w-full max-w-3xl">
        <flux:heading size="xl" level="1">{{ $targetUser->name }}</flux:heading>
        <flux:subheading size="lg" class="mb-6">{{ $targetUser->role->label() }} @if ($targetUser->team) &middot; {{ $targetUser->team->name }} @endif</flux:subheading>

        <form method="GET" action="{{ route('performance.individual', $targetUser) }}" class="mb-8 flex flex-wrap items-end gap-4">
            <flux:input name="period_start" label="Period start" type="date" value="{{ $periodStart->format('Y-m-d') }}" />
            <flux:input name="period_end" label="Period end" type="date" value="{{ $periodEnd->format('Y-m-d') }}" />
            <flux:button type="submit">Apply</flux:button>
        </form>

        @include('performance._snapshot')
    </div>
</x-layouts.app>
