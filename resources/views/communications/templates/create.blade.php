<x-layouts.app>
    <div class="w-full max-w-2xl">
        <flux:heading size="xl" level="1">New Template</flux:heading>
        <flux:separator variant="subtle" class="mb-6" />

        <form method="POST" action="{{ route('communications.templates.store') }}" class="space-y-6">
            @csrf

            <flux:input name="name" label="Name" required value="{{ old('name') }}" />

            <flux:select name="channel" label="Channel" required>
                @foreach (\App\Enums\CommunicationChannel::cases() as $channel)
                    <flux:select.option value="{{ $channel->value }}" :selected="old('channel') === $channel->value">{{ $channel->label() }}</flux:select.option>
                @endforeach
            </flux:select>

            @if ($teams->isNotEmpty())
                <flux:select name="team_id" label="Team" placeholder="Organisation-wide (visible to everyone)">
                    @foreach ($teams as $team)
                        <flux:select.option value="{{ $team->id }}" :selected="(string) old('team_id') === (string) $team->id">{{ $team->name }}</flux:select.option>
                    @endforeach
                </flux:select>
            @endif

            <flux:input name="subject" label="Subject (email only)" value="{{ old('subject') }}" />
            <flux:textarea name="body" label="Body" rows="6" required>{{ old('body') }}</flux:textarea>
            <p class="text-xs text-zinc-500 dark:text-zinc-400">
                Available placeholders: @{{first_name}}, @{{company_name}}, @{{salesperson_name}}. Plain text substitution only — no code is ever executed.
            </p>

            <div class="flex items-center gap-4">
                <flux:button type="submit" variant="primary">Create Template</flux:button>
                <flux:button href="{{ route('communications.templates.index') }}" variant="ghost" wire:navigate>Cancel</flux:button>
            </div>
        </form>
    </div>
</x-layouts.app>
