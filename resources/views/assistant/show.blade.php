@php
    $statusColor = fn ($status) => match ($status) {
        'failed' => 'red',
        'limit_reached' => 'amber',
        'queued', 'running' => 'blue',
        default => null,
    };
@endphp
<x-layouts.app>
    <div class="w-full max-w-3xl">
        <div class="mb-6 flex items-start justify-between">
            <div>
                <flux:heading size="xl" level="1">Assistant</flux:heading>
                <flux:subheading size="lg">Ask about your leads, opportunities, performance, or draft a message. It never sends anything on its own.</flux:subheading>
            </div>
            <form method="POST" action="{{ route('assistant.new-conversation') }}">
                @csrf
                <flux:button type="submit" size="sm">New conversation</flux:button>
            </form>
        </div>

        <div class="mb-6 space-y-4">
            @forelse ($conversation as $turn)
                @if ($turn['role'] === 'user')
                    <div class="ml-auto max-w-lg rounded-lg bg-zinc-100 p-3 text-sm dark:bg-zinc-700">
                        {{ $turn['content'] }}
                    </div>
                @else
                    <div class="max-w-lg rounded-lg border border-zinc-200 p-3 text-sm dark:border-zinc-700">
                        @if (! empty($turn['agent_label']))
                            <div class="mb-2 text-xs font-medium text-zinc-500 dark:text-zinc-400">{{ $turn['agent_label'] }}</div>
                        @endif

                        @if (! empty($turn['tools_used']))
                            <div class="mb-2 flex flex-wrap gap-1">
                                @foreach (array_unique($turn['tools_used']) as $toolName)
                                    <flux:badge size="sm">{{ str_replace('_', ' ', $toolName) }}</flux:badge>
                                @endforeach
                            </div>
                        @endif

                        @if (($turn['status'] ?? 'completed') !== 'completed')
                            <flux:badge size="sm" class="mb-2" :color="$statusColor($turn['status'])">{{ ucfirst(str_replace('_', ' ', $turn['status'])) }}</flux:badge>
                        @endif

                        @if (($turn['status'] ?? null) === 'queued')
                            <p class="text-sm text-zinc-500 dark:text-zinc-400">Market Intelligence research is queued…</p>
                        @elseif (($turn['status'] ?? null) === 'running')
                            <p class="text-sm text-zinc-500 dark:text-zinc-400">Researching prospects — this can take a few minutes. This page updates automatically; you can leave and come back.</p>
                        @else
                            <p class="whitespace-pre-line">{{ $turn['content'] ?? '(no response)' }}</p>
                        @endif
                    </div>
                @endif
            @empty
                <p class="text-sm text-zinc-500 dark:text-zinc-400">Ask a question to get started — e.g. "What is my current pipeline?" or "Which leads need follow-up?"</p>
            @endforelse
        </div>

        @if ($draft)
            <div class="mb-6 rounded-lg border border-blue-200 bg-blue-50 p-4 dark:border-blue-900 dark:bg-blue-950">
                <flux:heading size="lg" class="mb-2">
                    @if (($draft['draft'] ?? false) === true)
                        Draft {{ $draft['channel'] === 'email' ? 'Email' : 'WhatsApp Message' }}
                    @else
                        Draft Not Prepared
                    @endif
                </flux:heading>

                @if (($draft['draft'] ?? false) !== true)
                    <p class="text-sm text-zinc-600 dark:text-zinc-400">{{ $draft['message'] ?? 'This draft could not be prepared.' }}</p>
                @else
                    <dl class="mb-3 space-y-1 text-sm">
                        <div><dt class="inline font-medium">Channel:</dt> <dd class="inline">{{ ucfirst($draft['channel']) }}</dd></div>
                        <div><dt class="inline font-medium">Recipient:</dt> <dd class="inline">{{ $draft['recipient'] }}</dd></div>
                        @if ($draft['channel'] === 'email' && ! empty($draft['subject']))
                            <div><dt class="inline font-medium">Subject:</dt> <dd class="inline">{{ $draft['subject'] }}</dd></div>
                        @endif
                    </dl>
                    <p class="mb-4 rounded border border-zinc-200 bg-white p-3 whitespace-pre-line dark:border-zinc-700 dark:bg-zinc-900">{{ $draft['body'] }}</p>

                    <p class="mb-3 text-xs text-zinc-500 dark:text-zinc-400">Nothing has been sent. Review and send from the composer, where you'll confirm explicitly before anything goes out.</p>

                    <div class="flex gap-3">
                        @if ($draft['channel'] === 'email')
                            <flux:button
                                href="{{ route('communications.compose-email', array_filter([
                                    'recipient' => $draft['recipient'],
                                    'subject' => $draft['subject'],
                                    'body' => $draft['body'],
                                    'organization_id' => $draft['organization_id'] ?? null,
                                    'contact_id' => $draft['contact_id'] ?? null,
                                    'lead_id' => $draft['lead_id'] ?? null,
                                    'opportunity_id' => $draft['opportunity_id'] ?? null,
                                ])) }}"
                                variant="primary"
                                wire:navigate
                            >
                                Review &amp; Send
                            </flux:button>
                        @else
                            <flux:button
                                href="{{ route('communications.compose-whatsapp', array_filter([
                                    'recipient' => $draft['recipient'],
                                    'body' => $draft['body'],
                                    'whatsapp_number_id' => $draft['whatsapp_number_id'] ?? null,
                                    'organization_id' => $draft['organization_id'] ?? null,
                                    'contact_id' => $draft['contact_id'] ?? null,
                                    'lead_id' => $draft['lead_id'] ?? null,
                                    'opportunity_id' => $draft['opportunity_id'] ?? null,
                                ])) }}"
                                variant="primary"
                                wire:navigate
                            >
                                Review &amp; Send
                            </flux:button>
                        @endif

                        <form method="POST" action="{{ route('assistant.dismiss-draft') }}">
                            @csrf
                            <flux:button type="submit" variant="ghost">Dismiss</flux:button>
                        </form>
                    </div>
                @endif
            </div>
        @endif

        @if (! empty($pendingResearchRunIds))
            <div
                x-data="{
                    ids: @js($pendingResearchRunIds),
                    timer: null,
                    check() {
                        this.ids.forEach(async (id) => {
                            try {
                                const res = await fetch(`{{ url('assistant/research') }}/${id}/status`, {
                                    headers: { 'Accept': 'application/json' },
                                    credentials: 'same-origin',
                                });
                                if (!res.ok) return;
                                const data = await res.json();
                                if (data.done) {
                                    clearInterval(this.timer);
                                    window.location.reload();
                                }
                            } catch (e) { /* transient — try again next tick */ }
                        });
                    },
                }"
                x-init="timer = setInterval(() => check(), 4000)"
                x-on:livewire:navigating.window="clearInterval(timer)"
                wire:ignore
            ></div>
        @endif

        <form method="POST" action="{{ route('assistant.send-message') }}" class="space-y-3">
            @csrf
            <input type="hidden" name="submission_id" value="{{ $submissionId }}">
            <flux:select name="agent" label="Ask" placeholder="Auto — let the assistant pick">
                @foreach ($agents as $agentOption)
                    <flux:select.option value="{{ $agentOption->value }}" :selected="old('agent') === $agentOption->value">{{ $agentOption->label() }}</flux:select.option>
                @endforeach
            </flux:select>
            @error('agent')
                <p class="text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
            @enderror

            <flux:textarea name="message" rows="3" placeholder="Ask about your leads, opportunities, performance, or ask for a draft..." required>{{ old('message') }}</flux:textarea>
            @error('message')
                <p class="text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
            @enderror
            <flux:button type="submit" variant="primary">Send</flux:button>
        </form>
    </div>
</x-layouts.app>
