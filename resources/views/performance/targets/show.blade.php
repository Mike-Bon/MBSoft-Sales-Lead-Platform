<x-layouts.app>
    <div class="w-full max-w-4xl">
        <div class="mb-6 flex items-start justify-between">
            <div>
                <flux:heading size="xl" level="1">{{ $target->owner?->name ?? $target->team?->name ?? 'Target' }}</flux:heading>
                <flux:subheading size="lg">
                    <flux:badge size="sm">{{ $target->target_type->label() }}</flux:badge>
                    <flux:badge size="sm">{{ $target->period_type->label() }}</flux:badge>
                    <flux:badge size="sm">{{ $target->status->label() }}</flux:badge>
                </flux:subheading>
            </div>
            @can('update', $target)
                <flux:button href="{{ route('performance.targets.edit', $target) }}" wire:navigate>Edit</flux:button>
            @endcan
        </div>

        @if (session('status'))
            <flux:callout variant="success" class="mb-4" icon="check-circle">{{ session('status') }}</flux:callout>
        @endif

        @if ($target->notes)
            <div class="mb-8">
                <flux:heading size="lg" class="mb-1">Notes</flux:heading>
                <p class="whitespace-pre-line text-sm text-zinc-600 dark:text-zinc-400">{{ $target->notes }}</p>
            </div>
        @endif

        <flux:heading size="lg" class="mb-4">Performance</flux:heading>
        @include('performance._snapshot')
    </div>
</x-layouts.app>
