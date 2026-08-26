<x-layouts.app>
    <div class="w-full max-w-2xl">
        <flux:heading size="xl" level="1">Edit Contact</flux:heading>
        <flux:subheading size="lg" class="mb-6">{{ $contact->fullName() }}</flux:subheading>
        <flux:separator variant="subtle" class="mb-6" />

        <form method="POST" action="{{ route('crm.contacts.update', $contact) }}" class="space-y-6">
            @csrf
            @method('PUT')
            @include('crm.contacts._form')

            <div class="flex items-center gap-4">
                <flux:button type="submit" variant="primary">Save Changes</flux:button>
                <flux:button href="{{ route('crm.contacts.show', $contact) }}" variant="ghost" wire:navigate>Cancel</flux:button>
            </div>
        </form>

        <flux:separator variant="subtle" class="my-8" />

        <form method="POST" action="{{ route('crm.contacts.destroy', $contact) }}" onsubmit="return confirm('Archive this contact?');">
            @csrf
            @method('DELETE')
            <flux:button type="submit" variant="danger">Archive Contact</flux:button>
        </form>
    </div>
</x-layouts.app>
