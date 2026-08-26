<x-layouts.app>
    <div class="w-full">
        <flux:heading size="xl" level="1">{{ $team->name }} Dashboard</flux:heading>
        <flux:subheading size="lg" class="mb-6">Your team's performance and activity</flux:subheading>

        <x-performance.period-selector :period="$period" />

        @include('performance.teams._detail')
    </div>
</x-layouts.app>
