<x-layouts.app>
    <div class="w-full max-w-2xl">
        <flux:heading size="xl" level="1">Cost-to-Serve</flux:heading>
        <flux:subheading size="lg" class="mb-6">Revenue and sales-engagement intelligence by account</flux:subheading>

        <flux:callout icon="pause-circle" variant="secondary">
            <div class="mb-2 font-medium">Cost-to-Serve Intelligence is currently disabled.</div>
            <p class="mb-4 text-sm">
                No one — including you — can view Cost-to-Serve analysis or ask the assistant about it while
                the feature is off. You can turn it back on from Cost-to-Serve Settings.
            </p>
            <flux:button href="{{ route('cost-to-serve.settings') }}" variant="primary" wire:navigate>Go to Cost-to-Serve Settings</flux:button>
        </flux:callout>
    </div>
</x-layouts.app>
