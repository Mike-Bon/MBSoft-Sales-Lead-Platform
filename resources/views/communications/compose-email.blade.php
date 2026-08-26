<x-layouts.app>
    <div class="w-full max-w-2xl">
        <flux:heading size="xl" level="1">Send Email</flux:heading>
        <flux:separator variant="subtle" class="mb-6" />

        @unless ($account)
            <flux:callout variant="warning" icon="exclamation-triangle" class="mb-6">
                <flux:callout.heading>No Gmail account connected</flux:callout.heading>
                <flux:callout.text>
                    Connect your Gmail account before sending email.
                    <a class="underline" href="{{ route('communications.email-account.edit') }}" wire:navigate>Connect now</a>.
                </flux:callout.text>
            </flux:callout>
        @else
            <form method="POST" action="{{ route('communications.send-email') }}" class="space-y-6">
                @csrf
                <input type="hidden" name="organization_id" value="{{ old('organization_id', $context['organization_id']) }}" />
                <input type="hidden" name="contact_id" value="{{ old('contact_id', $context['contact_id']) }}" />
                <input type="hidden" name="lead_id" value="{{ old('lead_id', $context['lead_id']) }}" />
                <input type="hidden" name="opportunity_id" value="{{ old('opportunity_id', $context['opportunity_id']) }}" />

                <div class="rounded-lg border border-zinc-200 p-3 text-sm dark:border-zinc-700">
                    <span class="text-zinc-500 dark:text-zinc-400">Sending from:</span>
                    <span class="font-medium">{{ $account->email_address }}</span>
                </div>

                <flux:input name="recipient" label="Recipient" type="email" required value="{{ old('recipient', $context['recipient']) }}" />

                @if ($templates->isNotEmpty())
                    <flux:select name="template_id" label="Template" placeholder="None — write a custom message">
                        @foreach ($templates as $template)
                            <flux:select.option value="{{ $template->id }}" :selected="old('template_id') === (string) $template->id">{{ $template->name }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    <p class="text-xs text-zinc-500 dark:text-zinc-400">If you select a template, its own subject/body are sent instead of anything typed below — @{{first_name}}, @{{company_name}}, @{{salesperson_name}} are substituted automatically. Leave "None" selected to write a custom message.</p>
                @endif

                <flux:input name="subject" label="Subject (ignored if a template is selected)" value="{{ old('subject') }}" />
                <flux:textarea name="body" label="Message (ignored if a template is selected)" rows="8">{{ old('body') }}</flux:textarea>

                <flux:checkbox name="confirm" value="1" required :checked="old('confirm')" label="I confirm I want to send this message now. This action cannot be undone." />

                <div class="flex items-center gap-4">
                    <flux:button type="submit" variant="primary">Send Email</flux:button>
                    <flux:button href="{{ url()->previous() }}" variant="ghost">Cancel</flux:button>
                </div>
            </form>
        @endunless
    </div>
</x-layouts.app>
