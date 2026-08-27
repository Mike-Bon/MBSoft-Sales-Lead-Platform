<x-layouts.app>
    <div class="w-full max-w-2xl">
        <flux:heading size="xl" level="1">New Knowledge Document</flux:heading>
        <flux:separator variant="subtle" class="mb-6" />

        <form method="POST" action="{{ route('knowledge.store') }}" class="space-y-6">
            @csrf

            <flux:input name="title" label="Title" required value="{{ old('title') }}" />

            <flux:select name="type" label="Type" required>
                @foreach (\App\Enums\KnowledgeType::cases() as $type)
                    <flux:select.option value="{{ $type->value }}" :selected="old('type') === $type->value">{{ $type->label() }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:select name="visibility" label="Visibility" required>
                @foreach (\App\Enums\KnowledgeVisibility::cases() as $visibility)
                    <flux:select.option value="{{ $visibility->value }}" :selected="old('visibility') === $visibility->value">{{ $visibility->label() }}</flux:select.option>
                @endforeach
            </flux:select>

            @if ($teams->isNotEmpty())
                <flux:select name="team_id" label="Team (only used when visibility is Team)" placeholder="Select a team">
                    @foreach ($teams as $team)
                        <flux:select.option value="{{ $team->id }}" :selected="(string) old('team_id') === (string) $team->id">{{ $team->name }}</flux:select.option>
                    @endforeach
                </flux:select>
            @endif

            <flux:textarea name="raw_content" label="Content (plain text or Markdown)" rows="12" required>{{ old('raw_content') }}</flux:textarea>
            <p class="text-xs text-zinc-500 dark:text-zinc-400">
                Use Markdown headings (# / ##) to divide the document into sections — each section becomes a
                separately citable, separately searchable chunk. Content is processed asynchronously; the
                document becomes searchable once it reaches "Active" status.
            </p>

            <div class="grid grid-cols-2 gap-4">
                <flux:input type="date" name="effective_from" label="Effective from (optional)" value="{{ old('effective_from') }}" />
                <flux:input type="date" name="effective_until" label="Effective until (optional)" value="{{ old('effective_until') }}" />
            </div>

            <div class="flex items-center gap-4">
                <flux:button type="submit" variant="primary">Submit</flux:button>
                <flux:button href="{{ route('knowledge.index') }}" variant="ghost" wire:navigate>Cancel</flux:button>
            </div>
        </form>
    </div>
</x-layouts.app>
