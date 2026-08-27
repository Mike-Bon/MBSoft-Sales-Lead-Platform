<x-layouts.app>
    <div class="w-full max-w-3xl">
        <div class="mb-6 flex items-start justify-between">
            <div>
                <flux:heading size="xl" level="1">{{ $document->title }}</flux:heading>
                <p class="text-sm text-zinc-500 dark:text-zinc-400">
                    {{ $document->type->label() }} &middot; {{ $document->team?->name ?? $document->visibility->label() }}
                </p>
            </div>
            @can('delete', $document)
                <form method="POST" action="{{ route('knowledge.destroy', $document) }}" onsubmit="return confirm('Delete this document and all of its versions?');">
                    @csrf
                    @method('DELETE')
                    <flux:button type="submit" variant="danger">Delete</flux:button>
                </form>
            @endcan
        </div>

        @if (session('status'))
            <flux:callout variant="success" class="mb-4" icon="check-circle">{{ session('status') }}</flux:callout>
        @endif

        @if ($errors->any())
            <flux:callout variant="danger" class="mb-4" icon="exclamation-triangle">
                @foreach ($errors->all() as $error)
                    <p>{{ $error }}</p>
                @endforeach
            </flux:callout>
        @endif

        <flux:heading size="lg" class="mb-2">Versions</flux:heading>
        <flux:table class="mb-8">
            <flux:table.columns>
                <flux:table.column>Version</flux:table.column>
                <flux:table.column>Status</flux:table.column>
                <flux:table.column>Uploaded by</flux:table.column>
                <flux:table.column>Uploaded</flux:table.column>
                <flux:table.column></flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @foreach ($document->versions as $version)
                    <flux:table.row>
                        <flux:table.cell>v{{ $version->version_number }}</flux:table.cell>
                        <flux:table.cell>
                            <flux:badge size="sm" :color="match($version->status) {
                                \App\Enums\KnowledgeStatus::Active => 'green',
                                \App\Enums\KnowledgeStatus::Failed => 'red',
                                \App\Enums\KnowledgeStatus::Processing, \App\Enums\KnowledgeStatus::Draft => 'amber',
                                default => 'zinc',
                            }">{{ $version->status->label() }}</flux:badge>
                            @if ($version->status === \App\Enums\KnowledgeStatus::Failed && $version->processing_error)
                                <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $version->processing_error }}</p>
                            @endif
                        </flux:table.cell>
                        <flux:table.cell>{{ $version->uploadedBy?->name }}</flux:table.cell>
                        <flux:table.cell>{{ $version->created_at?->diffForHumans() }}</flux:table.cell>
                        <flux:table.cell>
                            @can('update', $document)
                                @if ($version->status === \App\Enums\KnowledgeStatus::Active)
                                    <form method="POST" action="{{ route('knowledge.versions.archive', [$document, $version]) }}">
                                        @csrf
                                        <flux:button type="submit" size="sm" variant="ghost">Archive</flux:button>
                                    </form>
                                @endif
                            @endcan
                        </flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>

        @can('update', $document)
            <flux:heading size="lg" class="mb-2">New Version</flux:heading>
            <p class="mb-4 text-sm text-zinc-500 dark:text-zinc-400">
                Replaces the current content. The document stays visible under its existing title; the
                previously active version is archived (not deleted) once the new one finishes processing.
            </p>

            <form method="POST" action="{{ route('knowledge.versions.store', $document) }}" class="space-y-6">
                @csrf
                <flux:textarea name="raw_content" label="Content (plain text or Markdown)" rows="12" required>{{ old('raw_content') }}</flux:textarea>

                <div class="grid grid-cols-2 gap-4">
                    <flux:input type="date" name="effective_from" label="Effective from (optional)" value="{{ old('effective_from') }}" />
                    <flux:input type="date" name="effective_until" label="Effective until (optional)" value="{{ old('effective_until') }}" />
                </div>

                <flux:button type="submit" variant="primary">Submit New Version</flux:button>
            </form>
        @endcan
    </div>
</x-layouts.app>
