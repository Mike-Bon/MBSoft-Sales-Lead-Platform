<x-layouts.app>
    <div class="w-full max-w-xl">
        <flux:heading size="xl" level="1">Gmail Account</flux:heading>
        <flux:separator variant="subtle" class="mb-6" />

        @if (session('status'))
            <flux:callout variant="success" class="mb-4" icon="check-circle">{{ session('status') }}</flux:callout>
        @endif
        @if (session('error'))
            <flux:callout variant="danger" class="mb-4" icon="exclamation-triangle">{{ session('error') }}</flux:callout>
        @endif

        @if ($account)
            <div class="mb-6 rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
                <div class="text-sm text-zinc-500 dark:text-zinc-400">Connected account</div>
                <div class="font-medium">{{ $account->email_address }}</div>
                <flux:badge size="sm" class="mt-2" :color="$account->status === \App\Enums\EmailAccountStatus::Connected ? 'green' : 'red'">{{ $account->status->label() }}</flux:badge>
            </div>

            <form method="POST" action="{{ route('communications.email-account.destroy') }}" onsubmit="return confirm('Disconnect this Gmail account? You will need to reconnect before sending email again.');">
                @csrf
                @method('DELETE')
                <flux:button type="submit" variant="danger">Disconnect</flux:button>
            </form>
        @else
            <p class="mb-6 text-sm text-zinc-600 dark:text-zinc-400">
                Connect your own Gmail account to send email from the CRM. This uses Google's official OAuth2 sign-in — your Gmail password is never seen or stored by this application.
            </p>
            <flux:button href="{{ route('communications.email-account.connect') }}" variant="primary">Connect Gmail Account</flux:button>
        @endif
    </div>
</x-layouts.app>
