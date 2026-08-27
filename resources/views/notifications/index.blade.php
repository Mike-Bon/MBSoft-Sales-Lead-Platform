<x-layouts.app>
    <div class="w-full max-w-2xl">
        <div class="mb-6 flex items-start justify-between">
            <flux:heading size="xl" level="1">Notifications</flux:heading>
            @if ($notifications->contains(fn ($n) => $n->read_at === null))
                <form method="POST" action="{{ route('notifications.read-all') }}">
                    @csrf
                    <flux:button type="submit" variant="ghost" size="sm">Mark all read</flux:button>
                </form>
            @endif
        </div>

        <div class="space-y-3">
            @forelse ($notifications as $notification)
                @php
                    // Phase 11A: an icon + short kind label so a
                    // pending-approval (actionable) and a failed-send
                    // (informational) are distinguishable at a glance,
                    // not by their body text alone (section 21 — never
                    // rely on color/text alone without a clear label).
                    $data = $notification->data;
                    [$label, $url, $kindLabel, $icon, $iconClass] = match ($data['kind'] ?? null) {
                        'workflow_approval_pending' => [
                            'A draft '.$data['channel'].' to '.$data['recipient'].' is waiting for your review.',
                            route($data['channel'] === 'whatsapp' ? 'communications.compose-whatsapp' : 'communications.compose-email', ['workflow_approval_id' => $data['workflow_approval_id']]),
                            'Action needed',
                            'clock',
                            'text-amber-600 dark:text-amber-400',
                        ],
                        'communication_failed' => [
                            'Your '.$data['channel'].' to '.$data['recipient'].' could not be sent'.($data['failure_reason'] ? ': '.$data['failure_reason'] : '.'),
                            route('communications.show', $data['communication_id']),
                            'Send failed',
                            'exclamation-circle',
                            'text-red-600 dark:text-red-400',
                        ],
                        default => ['Notification', null, null, 'bell', 'text-zinc-400'],
                    };
                @endphp
                <div class="flex items-start justify-between gap-4 rounded-lg border border-zinc-200 p-4 dark:border-zinc-700 {{ $notification->read_at ? 'opacity-60' : '' }}">
                    <div class="flex items-start gap-3">
                        <flux:icon :name="$icon" class="mt-0.5 size-5 shrink-0 {{ $iconClass }}" />
                        <div>
                            @if ($kindLabel)
                                <flux:badge size="sm" :color="$data['kind'] === 'workflow_approval_pending' ? 'amber' : 'red'" class="mb-1">{{ $kindLabel }}</flux:badge>
                            @endif
                            <p class="text-sm">
                                @if ($url)
                                    <a href="{{ $url }}" class="underline" wire:navigate>{{ $label }}</a>
                                @else
                                    {{ $label }}
                                @endif
                            </p>
                            <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">{{ $notification->created_at->diffForHumans() }}</p>
                        </div>
                    </div>
                    @if (! $notification->read_at)
                        <form method="POST" action="{{ route('notifications.read', $notification->id) }}">
                            @csrf
                            <flux:button type="submit" variant="ghost" size="sm">Mark read</flux:button>
                        </form>
                    @endif
                </div>
            @empty
                <p class="py-6 text-center text-sm text-zinc-500 dark:text-zinc-400">No notifications yet.</p>
            @endforelse
        </div>

        <div class="mt-4">{{ $notifications->links() }}</div>
    </div>
</x-layouts.app>
