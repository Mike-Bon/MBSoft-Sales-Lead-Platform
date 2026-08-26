<x-layouts.app>
    <div class="w-full max-w-2xl">
        <flux:heading size="xl" level="1">Edit Template</flux:heading>
        <flux:subheading size="lg">{{ $template->channel->label() }} &middot; {{ $template->team?->name ?? 'Organisation-wide' }}</flux:subheading>
        <flux:separator variant="subtle" class="mb-6" />

        <form method="POST" action="{{ route('communications.templates.update', $template) }}" class="space-y-6">
            @csrf
            @method('PUT')

            <flux:input name="name" label="Name" required value="{{ old('name', $template->name) }}" />
            <flux:input name="subject" label="Subject (email only)" value="{{ old('subject', $template->subject) }}" />
            <flux:textarea name="body" label="Body" rows="6" required>{{ old('body', $template->body) }}</flux:textarea>
            <p class="text-xs text-zinc-500 dark:text-zinc-400">
                Available placeholders: @{{first_name}}, @{{company_name}}, @{{salesperson_name}}. Plain text substitution only — no code is ever executed.
            </p>

            <flux:select name="status" label="Status" required>
                @foreach (\App\Enums\RecordStatus::cases() as $status)
                    <flux:select.option value="{{ $status->value }}" :selected="old('status', $template->status->value) === $status->value">{{ $status->label() }}</flux:select.option>
                @endforeach
            </flux:select>

            <div class="flex items-center gap-4">
                <flux:button type="submit" variant="primary">Save Changes</flux:button>
                <flux:button href="{{ route('communications.templates.index') }}" variant="ghost" wire:navigate>Cancel</flux:button>
            </div>
        </form>

        @can('delete', $template)
            <flux:separator variant="subtle" class="my-8" />
            <form method="POST" action="{{ route('communications.templates.destroy', $template) }}" onsubmit="return confirm('Remove this template?');">
                @csrf
                @method('DELETE')
                <flux:button type="submit" variant="danger">Delete Template</flux:button>
            </form>
        @endcan
    </div>
</x-layouts.app>
