<x-layouts.app>
    <div class="w-full">
        <div class="mb-2 flex items-center justify-between">
            <div>
                <flux:heading size="xl" level="1">{{ $team->name }}</flux:heading>
                <flux:subheading size="lg">Team performance detail</flux:subheading>
            </div>
            @can('viewAny', App\Models\Team::class)
                <flux:button href="{{ route('dashboard') }}" variant="ghost" wire:navigate>Back to Dashboard</flux:button>
            @endcan
        </div>

        <x-performance.period-selector :period="$period" />

        @include('performance.teams._detail')
    </div>
</x-layouts.app>
