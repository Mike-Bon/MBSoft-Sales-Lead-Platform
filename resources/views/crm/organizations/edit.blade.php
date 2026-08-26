<x-layouts.app>
    <div class="w-full max-w-2xl">
        <flux:heading size="xl" level="1">Edit Organization</flux:heading>
        <flux:subheading size="lg" class="mb-6">{{ $organization->name }}</flux:subheading>
        <flux:separator variant="subtle" class="mb-6" />

        <form method="POST" action="{{ route('crm.organizations.update', $organization) }}" class="space-y-6">
            @csrf
            @method('PUT')
            @include('crm.organizations._form')

            <div class="flex items-center gap-4">
                <flux:button type="submit" variant="primary">Save Changes</flux:button>
                <flux:button href="{{ route('crm.organizations.show', $organization) }}" variant="ghost" wire:navigate>Cancel</flux:button>
            </div>
        </form>

        <flux:separator variant="subtle" class="my-8" />

        <form method="POST" action="{{ route('crm.organizations.destroy', $organization) }}" onsubmit="return confirm('Archive this organization?');">
            @csrf
            @method('DELETE')
            <flux:button type="submit" variant="danger">Archive Organization</flux:button>
        </form>
    </div>
</x-layouts.app>
