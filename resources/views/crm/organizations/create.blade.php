<x-layouts.app>
    <div class="w-full max-w-2xl">
        <flux:heading size="xl" level="1">New Organization</flux:heading>
        <flux:separator variant="subtle" class="mb-6" />

        <form method="POST" action="{{ route('crm.organizations.store') }}" class="space-y-6">
            @csrf
            @include('crm.organizations._form')

            <div class="flex items-center gap-4">
                <flux:button type="submit" variant="primary">Create Organization</flux:button>
                <flux:button href="{{ route('crm.organizations.index') }}" variant="ghost" wire:navigate>Cancel</flux:button>
            </div>
        </form>
    </div>
</x-layouts.app>
