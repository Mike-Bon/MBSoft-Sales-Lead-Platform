<x-layouts.app>
    <div class="w-full max-w-2xl">
        <flux:heading size="xl" level="1">Edit Target</flux:heading>
        <flux:subheading size="lg" class="mb-6">{{ $target->target_type->label() }} — {{ $target->owner?->name ?? $target->team?->name }}</flux:subheading>
        <flux:separator variant="subtle" class="mb-6" />

        <form method="POST" action="{{ route('performance.targets.update', $target) }}" class="space-y-6">
            @csrf
            @method('PUT')
            @include('performance.targets._form')

            <div class="flex items-center gap-4">
                <flux:button type="submit" variant="primary">Save Changes</flux:button>
                <flux:button href="{{ route('performance.targets.show', $target) }}" variant="ghost" wire:navigate>Cancel</flux:button>
            </div>
        </form>

        <flux:separator variant="subtle" class="my-8" />

        <form method="POST" action="{{ route('performance.targets.destroy', $target) }}" onsubmit="return confirm('Deactivate this target? It will stop counting toward performance calculations.');">
            @csrf
            @method('DELETE')
            <flux:button type="submit" variant="danger">Deactivate Target</flux:button>
        </form>
    </div>
</x-layouts.app>
