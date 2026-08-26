<x-layouts.app>
    <div class="w-full max-w-2xl">
        <flux:heading size="xl" level="1">Send WhatsApp Message</flux:heading>
        <flux:separator variant="subtle" class="mb-6" />

        @if ($numbers->isEmpty())
            <flux:callout variant="warning" icon="exclamation-triangle" class="mb-6">
                <flux:callout.heading>No WhatsApp number available</flux:callout.heading>
                <flux:callout.text>There is no connected WhatsApp business number available to you yet. Ask a Manager to register one.</flux:callout.text>
            </flux:callout>
        @else
            <form method="POST" action="{{ route('communications.send-whatsapp') }}" class="space-y-6">
                @csrf
                <input type="hidden" name="organization_id" value="{{ old('organization_id', $context['organization_id']) }}" />
                <input type="hidden" name="contact_id" value="{{ old('contact_id', $context['contact_id']) }}" />
                <input type="hidden" name="lead_id" value="{{ old('lead_id', $context['lead_id']) }}" />
                <input type="hidden" name="opportunity_id" value="{{ old('opportunity_id', $context['opportunity_id']) }}" />
                @if ($context['workflow_approval_id'] ?? null)
                    <input type="hidden" name="workflow_approval_id" value="{{ $context['workflow_approval_id'] }}" />
                    <flux:callout variant="secondary" icon="sparkles">
                        <flux:callout.text>This message was proposed by an AI workflow. Review it carefully before sending — sending it here will mark that proposal approved.</flux:callout.text>
                    </flux:callout>
                @endif

                <flux:select name="whatsapp_number_id" label="Send from" required>
                    @foreach ($numbers as $number)
                        <flux:select.option value="{{ $number->id }}" :selected="(string) old('whatsapp_number_id', $context['whatsapp_number_id'] ?? request()->query('whatsapp_number_id')) === (string) $number->id">{{ $number->display_name }} ({{ $number->phone_number }})</flux:select.option>
                    @endforeach
                </flux:select>

                <flux:input name="recipient" label="Recipient (E.164 format, e.g. +15551234567)" required value="{{ old('recipient', $context['recipient_phone']) }}" />

                @if ($templates->isNotEmpty())
                    <flux:select name="template_id" label="Template" placeholder="None — write a custom message">
                        @foreach ($templates as $template)
                            <flux:select.option value="{{ $template->id }}" :selected="old('template_id') === (string) $template->id">{{ $template->name }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    <p class="text-xs text-zinc-500 dark:text-zinc-400">If you select a template, its own body is sent instead of anything typed below.</p>
                @endif

                <flux:textarea name="body" label="Message (ignored if a template is selected)" rows="6">{{ old('body', $context['body']) }}</flux:textarea>

                <flux:callout variant="secondary" icon="information-circle">
                    <flux:callout.text>WhatsApp only allows free-form text to a recipient who has messaged this number within the last 24 hours. Outside that window, only a pre-approved template message will be delivered.</flux:callout.text>
                </flux:callout>

                <flux:checkbox name="confirm" value="1" required :checked="old('confirm')" label="I confirm I want to send this message now. This action cannot be undone." />

                <div class="flex items-center gap-4">
                    <flux:button type="submit" variant="primary">Send WhatsApp Message</flux:button>
                    <flux:button href="{{ url()->previous() }}" variant="ghost">Cancel</flux:button>
                </div>
            </form>
        @endif
    </div>
</x-layouts.app>
