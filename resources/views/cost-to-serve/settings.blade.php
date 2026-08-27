<x-layouts.app>
    <div class="w-full max-w-2xl">
        <flux:heading size="xl" level="1">Cost-to-Serve Settings</flux:heading>
        <flux:subheading size="lg" class="mb-6">Control whether Cost-to-Serve Intelligence is available</flux:subheading>

        @if (session('status'))
            <flux:callout variant="success" class="mb-4" icon="check-circle">{{ session('status') }}</flux:callout>
        @endif

        <div class="rounded-lg border border-zinc-200 p-6 dark:border-zinc-700">
            <div class="mb-4 flex items-center justify-between">
                <div>
                    <div class="mb-1 text-sm text-zinc-500 dark:text-zinc-400">Status</div>
                    <div class="flex items-center gap-2">
                        <span class="flex h-2.5 w-2.5 rounded-full {{ $enabled ? 'bg-success' : 'bg-zinc-400' }}"></span>
                        <span class="text-lg font-semibold">{{ $enabled ? 'Enabled' : 'Disabled' }}</span>
                    </div>
                </div>

                <form
                    method="POST"
                    action="{{ route('cost-to-serve.settings.update') }}"
                    onsubmit="return confirm('{{ $enabled ? 'Disable Cost-to-Serve Intelligence?\n\nThis will disable Cost-to-Serve access for the Manager and all currently authorized users. You can re-enable it from Cost-to-Serve Settings.' : 'Enable Cost-to-Serve Intelligence?\n\nThis will enable Cost-to-Serve for authorized users. Team Heads will remain unauthorized.' }}');"
                >
                    @csrf
                    <input type="hidden" name="enabled" value="{{ $enabled ? '0' : '1' }}" />
                    <flux:button type="submit" :variant="$enabled ? 'danger' : 'primary'">
                        {{ $enabled ? 'Turn Off' : 'Turn On' }}
                    </flux:button>
                </form>
            </div>

            <p class="text-sm text-zinc-600 dark:text-zinc-300">
                @if ($enabled)
                    Cost-to-Serve analysis is available to authorized users.
                @else
                    Cost-to-Serve analysis is currently disabled. You retain access to this settings page
                    so you can turn it back on at any time.
                @endif
            </p>
        </div>

        <flux:callout icon="information-circle" variant="secondary" class="mt-6">
            Only the Manager role can ever access Cost-to-Serve. Enabling this feature does <strong>not</strong>
            grant access to Team Heads — there is no per-Team-Head toggle.
        </flux:callout>
    </div>
</x-layouts.app>
