@php
    use App\Enums\ProspectLeadEligibility;
    use App\Enums\ProspectProposalStatus;

    $snapshot = $proposal->prospect_snapshot ?? [];
    $org = $proposal->proposed_organization ?? [];
    $eligibility = $proposal->eligibility;
    $canConfirm = $proposal->status === ProspectProposalStatus::Pending && ! $expired && $eligibility->canReachConfirmation();
    $blocked = $eligibility->isBlocked();
@endphp

<x-layouts.app>
    <div class="w-full max-w-2xl">
        <flux:heading size="xl" level="1">Create CRM Lead from Prospect Research</flux:heading>
        <flux:subheading size="lg">
            Nothing has been written to the CRM. Review the proposed data below, then explicitly confirm.
            The assistant cannot create this lead — only you can.
        </flux:subheading>
        <flux:separator variant="subtle" class="my-6" />

        @if (session('proposal_error'))
            <flux:callout variant="danger" class="mb-6" icon="exclamation-triangle">{{ session('proposal_error') }}</flux:callout>
        @endif
        @if (session('status'))
            <flux:callout variant="success" class="mb-6" icon="check-circle">{{ session('status') }}</flux:callout>
        @endif

        @if ($proposal->status === ProspectProposalStatus::Confirmed)
            <flux:callout variant="success" class="mb-6" icon="check-circle">
                This proposal was already confirmed.
                <flux:link href="{{ route('crm.leads.show', $proposal->lead_id) }}">Open the lead</flux:link>.
            </flux:callout>
        @elseif ($proposal->status->isDecided())
            <flux:callout variant="warning" class="mb-6">This proposal is {{ $proposal->status->label() }}. Prepare the prospect again to create a lead.</flux:callout>
        @elseif ($expired)
            <flux:callout variant="warning" class="mb-6">This proposal has expired. Prepare the prospect again to create a lead.</flux:callout>
        @endif

        {{-- Intelligence summary (read-only, system-controlled) --}}
        <div class="mb-6 rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
            <div class="text-lg font-semibold">{{ $proposal->business_name }}</div>
            <dl class="mt-2 grid grid-cols-2 gap-x-4 gap-y-1 text-sm">
                <dt class="text-zinc-500">Qualification</dt>
                <dd>{{ \Illuminate\Support\Str::headline($snapshot['qualification_outcome'] ?? 'n/a') }}</dd>
                <dt class="text-zinc-500">Prioritisation score</dt>
                <dd>{{ $snapshot['total_score'] ?? 'n/a' }} / 100 — {{ strtoupper($snapshot['priority'] ?? 'n/a') }}</dd>
                <dt class="text-zinc-500">Scoring model</dt>
                <dd>{{ $snapshot['scoring_model'] ?? 'n/a' }}</dd>
                <dt class="text-zinc-500">Duplicate check</dt>
                <dd>{{ $snapshot['duplicate_status_label'] ?? \Illuminate\Support\Str::headline($proposal->duplicate_status ?? $proposal->duplicate_check_status) }}</dd>
            </dl>
            @if (! empty($snapshot['missing_information']))
                <div class="mt-3 text-xs text-zinc-500">
                    <div class="font-medium">Still unknown:</div>
                    <ul class="ml-4 list-disc">
                        @foreach ($snapshot['missing_information'] as $item)
                            <li>{{ $item }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif
        </div>

        {{-- Duplicate warning --}}
        @if ($eligibility === ProspectLeadEligibility::ReviewRequired && ! empty($snapshot['candidate_matches']))
            <flux:callout variant="warning" class="mb-6" icon="exclamation-triangle">
                <flux:callout.heading>Possible duplicate in your CRM</flux:callout.heading>
                <flux:callout.text>
                    <ul class="ml-4 list-disc">
                        @foreach ($snapshot['candidate_matches'] as $match)
                            <li>
                                <span class="font-medium">{{ $match['business_name'] ?? 'CRM record' }}</span>
                                @if (! empty($match['website'])) — {{ $match['website'] }} @endif
                                @if (! empty($match['match_reasons']))
                                    <div class="text-xs">Reasons: {{ collect($match['match_reasons'])->pluck('label')->join(', ') }}</div>
                                @endif
                                <flux:link href="{{ route('crm.organizations.show', $match['crm_record_id']) }}">Review existing record</flux:link>
                            </li>
                        @endforeach
                    </ul>
                </flux:callout.text>
            </flux:callout>
        @elseif ($eligibility === ProspectLeadEligibility::BlockedDuplicate)
            <flux:callout variant="danger" class="mb-6" icon="x-circle">
                <flux:callout.heading>This business is already in your CRM</flux:callout.heading>
                <flux:callout.text>
                    No new lead will be created.
                    @foreach ($snapshot['candidate_matches'] ?? [] as $match)
                        <div class="mt-1">
                            <span class="font-medium">{{ $match['business_name'] ?? 'CRM record' }}</span> —
                            <flux:link href="{{ route('crm.organizations.show', $match['crm_record_id']) }}">Review existing record</flux:link>
                        </div>
                    @endforeach
                </flux:callout.text>
            </flux:callout>
        @elseif ($blocked)
            <flux:callout variant="danger" class="mb-6" icon="x-circle">
                <flux:callout.heading>{{ $eligibility->label() }}</flux:callout.heading>
                <flux:callout.text>A lead cannot be created from this prospect right now. Run the duplicate check again from the assistant.</flux:callout.text>
            </flux:callout>
        @endif

        {{-- Proposed CRM data — editable --}}
        <form method="POST" action="{{ route('market-intelligence.prospect-proposals.confirm', $proposal) }}" class="space-y-4"
              onsubmit="return confirm('Create this prospect as a new CRM lead?');">
            @csrf
            <input type="hidden" name="fingerprint" value="{{ $proposal->fingerprint }}">

            <flux:heading size="lg">Proposed CRM data</flux:heading>
            <flux:text size="sm" class="text-zinc-500">Source-derived from the research. Edit anything that is wrong before creating the lead.</flux:text>

            <flux:input name="business_name" label="Business name" value="{{ old('business_name', $org['name'] ?? $proposal->business_name) }}" :disabled="! $canConfirm" required />
            <flux:input name="industry" label="Industry / category" value="{{ old('industry', $org['industry'] ?? '') }}" :disabled="! $canConfirm" />
            <flux:input name="website" label="Website" value="{{ old('website', $org['website'] ?? '') }}" :disabled="! $canConfirm" />
            <div class="grid grid-cols-2 gap-3">
                <flux:input name="city" label="City" value="{{ old('city', $org['city'] ?? '') }}" :disabled="! $canConfirm" />
                <flux:input name="country" label="Country" value="{{ old('country', $org['country'] ?? '') }}" :disabled="! $canConfirm" />
            </div>
            <flux:textarea name="lead_description" label="Lead notes" rows="3" :disabled="! $canConfirm">{{ old('lead_description', $proposal->proposed_lead['description'] ?? '') }}</flux:textarea>

            @error('business_name') <flux:text class="text-red-600">{{ $message }}</flux:text> @enderror
            @error('fingerprint') <flux:text class="text-red-600">{{ $message }}</flux:text> @enderror

            @if ($eligibility === ProspectLeadEligibility::ReviewRequired)
                <flux:checkbox
                    name="acknowledge_possible_duplicate"
                    value="1"
                    label="I reviewed the possible duplicate above and still want to create a new lead."
                    :disabled="! $canConfirm"
                />
                @error('acknowledge_possible_duplicate') <flux:text class="text-red-600">{{ $message }}</flux:text> @enderror
            @endif

            <div class="flex gap-3 pt-2">
                @if ($canConfirm)
                    <flux:button type="submit" variant="primary">Create Lead</flux:button>
                @endif
                <flux:button
                    type="submit"
                    variant="ghost"
                    formaction="{{ route('market-intelligence.prospect-proposals.cancel', $proposal) }}"
                    formnovalidate
                >
                    Cancel — do not create
                </flux:button>
            </div>
        </form>
    </div>
</x-layouts.app>
