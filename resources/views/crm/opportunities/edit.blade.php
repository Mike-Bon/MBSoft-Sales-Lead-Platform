<x-layouts.app>
    <div class="w-full max-w-2xl">
        <flux:heading size="xl" level="1">Edit Opportunity</flux:heading>
        <flux:subheading size="lg" class="mb-6">{{ $opportunity->name }}</flux:subheading>
        <flux:separator variant="subtle" class="mb-6" />

        <form method="POST" action="{{ route('crm.opportunities.update', $opportunity) }}" class="space-y-6">
            @csrf
            @method('PUT')
            @include('crm.opportunities._form')

            <div class="flex items-center gap-4">
                <flux:button type="submit" variant="primary">Save Changes</flux:button>
                <flux:button href="{{ route('crm.opportunities.show', $opportunity) }}" variant="ghost" wire:navigate>Cancel</flux:button>
            </div>
        </form>

        <flux:separator variant="subtle" class="my-8" />

        <form method="POST" action="{{ route('crm.opportunities.destroy', $opportunity) }}" onsubmit="return confirm('Archive this opportunity?');">
            @csrf
            @method('DELETE')
            <flux:button type="submit" variant="danger">Archive Opportunity</flux:button>
        </form>
    </div>
</x-layouts.app>
