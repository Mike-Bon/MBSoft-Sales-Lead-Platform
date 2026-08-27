<x-layouts.app>
    <div class="w-full">
        <div class="mb-6 flex items-start justify-between">
            <div>
                <flux:heading size="xl" level="1">Knowledge</flux:heading>
                <p class="text-sm text-zinc-500 dark:text-zinc-400">Company policies, SOPs, and playbooks the AI assistant can cite.</p>
            </div>
            @can('create', \App\Models\KnowledgeDocument::class)
                <flux:button href="{{ route('knowledge.create') }}" variant="primary" wire:navigate>New Document</flux:button>
            @endcan
        </div>

        @if (session('status'))
            <flux:callout variant="success" class="mb-4" icon="check-circle">{{ session('status') }}</flux:callout>
        @endif

        <flux:table>
            <flux:table.columns>
                <flux:table.column>Title</flux:table.column>
                <flux:table.column>Type</flux:table.column>
                <flux:table.column>Visibility</flux:table.column>
                <flux:table.column>Current version</flux:table.column>
                <flux:table.column>Created by</flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @forelse ($documents as $document)
                    <flux:table.row>
                        <flux:table.cell>
                            <a class="underline" href="{{ route('knowledge.show', $document) }}" wire:navigate>{{ $document->title }}</a>
                        </flux:table.cell>
                        <flux:table.cell>{{ $document->type->label() }}</flux:table.cell>
                        <flux:table.cell>{{ $document->team?->name ?? $document->visibility->label() }}</flux:table.cell>
                        <flux:table.cell>
                            @if ($document->currentVersion)
                                <flux:badge size="sm" color="green">v{{ $document->currentVersion->version_number }} active</flux:badge>
                            @else
                                <flux:badge size="sm" color="zinc">Processing</flux:badge>
                            @endif
                        </flux:table.cell>
                        <flux:table.cell>{{ $document->createdBy?->name }}</flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row>
                        <flux:table.cell colspan="5">
                            <p class="py-6 text-center text-sm text-zinc-500 dark:text-zinc-400">No knowledge documents yet.</p>
                        </flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
    </div>
</x-layouts.app>
