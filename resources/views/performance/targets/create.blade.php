<x-layouts.app>
    <div class="w-full max-w-2xl">
        <flux:heading size="xl" level="1">New Target</flux:heading>
        <flux:separator variant="subtle" class="mb-6" />

        <form method="POST" action="{{ route('performance.targets.store') }}" class="space-y-6">
            @csrf
            @include('performance.targets._form')

            <div class="flex items-center gap-4">
                <flux:button type="submit" variant="primary">Create Target</flux:button>
                <flux:button href="{{ route('performance.targets.index') }}" variant="ghost" wire:navigate>Cancel</flux:button>
            </div>
        </form>
    </div>
</x-layouts.app>
