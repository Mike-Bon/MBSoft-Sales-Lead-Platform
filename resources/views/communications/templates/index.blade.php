<x-layouts.app>
    <div class="w-full">
        <div class="mb-6 flex items-start justify-between">
            <flux:heading size="xl" level="1">Message Templates</flux:heading>
            <flux:button href="{{ route('communications.templates.create') }}" variant="primary" wire:navigate>New Template</flux:button>
        </div>

        @if (session('status'))
            <flux:callout variant="success" class="mb-4" icon="check-circle">{{ session('status') }}</flux:callout>
        @endif

        <flux:table>
            <flux:table.columns>
                <flux:table.column>Name</flux:table.column>
                <flux:table.column>Channel</flux:table.column>
                <flux:table.column>Scope</flux:table.column>
                <flux:table.column>Status</flux:table.column>
                <flux:table.column>Created by</flux:table.column>
                <flux:table.column></flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @forelse ($templates as $template)
                    <flux:table.row>
                        <flux:table.cell>{{ $template->name }}</flux:table.cell>
                        <flux:table.cell>{{ $template->channel->label() }}</flux:table.cell>
                        <flux:table.cell>{{ $template->team?->name ?? 'Organisation-wide' }}</flux:table.cell>
                        <flux:table.cell><flux:badge size="sm" :color="$template->status === \App\Enums\RecordStatus::Active ? 'green' : 'zinc'">{{ $template->status->label() }}</flux:badge></flux:table.cell>
                        <flux:table.cell>{{ $template->createdBy?->name }}</flux:table.cell>
                        <flux:table.cell>
                            @can('update', $template)
                                <a class="underline" href="{{ route('communications.templates.edit', $template) }}" wire:navigate>Edit</a>
                            @endcan
                        </flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row>
                        <flux:table.cell colspan="6">
                            <p class="py-6 text-center text-sm text-zinc-500 dark:text-zinc-400">No templates yet.</p>
                        </flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
    </div>
</x-layouts.app>
