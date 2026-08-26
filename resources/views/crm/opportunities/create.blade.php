<x-layouts.app>
    <div class="w-full max-w-2xl">
        <flux:heading size="xl" level="1">New Opportunity</flux:heading>
        <flux:separator variant="subtle" class="mb-6" />

        <form method="POST" action="{{ route('crm.opportunities.store') }}" class="space-y-6">
            @csrf
            @include('crm.opportunities._form')

            <div class="flex items-center gap-4">
                <flux:button type="submit" variant="primary">Create Opportunity</flux:button>
                <flux:button href="{{ route('crm.opportunities.index') }}" variant="ghost" wire:navigate>Cancel</flux:button>
            </div>
        </form>
    </div>
</x-layouts.app>
