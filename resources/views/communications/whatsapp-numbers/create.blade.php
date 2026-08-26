<x-layouts.app>
    <div class="w-full max-w-2xl">
        <flux:heading size="xl" level="1">Register WhatsApp Number</flux:heading>
        <flux:subheading size="lg">The number must already be configured in Meta Business Manager — this only records its identifiers.</flux:subheading>
        <flux:separator variant="subtle" class="mb-6" />

        <form method="POST" action="{{ route('communications.whatsapp-numbers.store') }}" class="space-y-6">
            @csrf

            <flux:input name="display_name" label="Display Name" required value="{{ old('display_name') }}" />
            <flux:input name="phone_number" label="Phone Number" required value="{{ old('phone_number') }}" placeholder="+15551234567" />
            <flux:input name="phone_number_id" label="Phone Number ID" required value="{{ old('phone_number_id') }}" description="From Meta Business Manager → WhatsApp → API Setup." />
            <flux:input name="waba_id" label="WhatsApp Business Account ID" value="{{ old('waba_id') }}" />

            <flux:select name="team_id" label="Team" placeholder="Organisation-wide (available to every team)">
                @foreach ($teams as $team)
                    <flux:select.option value="{{ $team->id }}" :selected="(string) old('team_id') === (string) $team->id">{{ $team->name }}</flux:select.option>
                @endforeach
            </flux:select>

            <div class="flex items-center gap-4">
                <flux:button type="submit" variant="primary">Register Number</flux:button>
                <flux:button href="{{ route('communications.whatsapp-numbers.index') }}" variant="ghost" wire:navigate>Cancel</flux:button>
            </div>
        </form>
    </div>
</x-layouts.app>
