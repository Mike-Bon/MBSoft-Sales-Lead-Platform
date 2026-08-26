<x-layouts.app>
    <div class="w-full max-w-2xl">
        <flux:heading size="xl" level="1">Edit Lead</flux:heading>
        <flux:subheading size="lg" class="mb-6">{{ $lead->organization?->name ?? $lead->contact?->fullName() ?? "Lead #{$lead->id}" }}</flux:subheading>
        <flux:separator variant="subtle" class="mb-6" />

        <form method="POST" action="{{ route('crm.leads.update', $lead) }}" class="space-y-6">
            @csrf
            @method('PUT')
            @include('crm.leads._form')

            <div class="flex items-center gap-4">
                <flux:button type="submit" variant="primary">Save Changes</flux:button>
                <flux:button href="{{ route('crm.leads.show', $lead) }}" variant="ghost" wire:navigate>Cancel</flux:button>
            </div>
        </form>

        <flux:separator variant="subtle" class="my-8" />

        <form method="POST" action="{{ route('crm.leads.destroy', $lead) }}" onsubmit="return confirm('Archive this lead?');">
            @csrf
            @method('DELETE')
            <flux:button type="submit" variant="danger">Archive Lead</flux:button>
        </form>
    </div>
</x-layouts.app>
