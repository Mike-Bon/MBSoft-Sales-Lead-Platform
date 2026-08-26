<x-layouts.app>
    <div class="w-full max-w-xl">
        <flux:heading size="xl" level="1">Create Team</flux:heading>
        <flux:subheading size="lg" class="mb-6">You can assign a Team Head after the team is created</flux:subheading>
        <flux:separator variant="subtle" class="mb-6" />

        <form method="POST" action="{{ route('organisation.teams.store') }}" class="space-y-6">
            @csrf

            <flux:input name="name" label="Name" type="text" value="{{ old('name') }}" required autofocus />
            <flux:input name="code" label="Code" type="text" value="{{ old('code') }}" description="Optional short identifier, e.g. T01" />

            <div class="flex items-center gap-4">
                <flux:button type="submit" variant="primary">Create Team</flux:button>
                <flux:button href="{{ route('organisation.teams.index') }}" variant="ghost" wire:navigate>Cancel</flux:button>
            </div>
        </form>
    </div>
</x-layouts.app>
