{{--
    STEP 11's period selector — current/previous month, current/previous
    quarter, current year, or a custom date range. Submits back to the
    current URL as a GET so it composes with whatever other filters
    (team, sort, member) that page already has in its query string.
--}}
@props(['period'])

<form method="GET" class="mb-6 flex flex-wrap items-end gap-4">
    @foreach (request()->except(['period', 'period_start', 'period_end']) as $key => $value)
        <input type="hidden" name="{{ $key }}" value="{{ $value }}" />
    @endforeach

    <flux:select name="period" label="Period" onchange="this.form.requestSubmit()">
        @foreach (\App\Enums\PeriodPreset::selectable() as $preset)
            <flux:select.option value="{{ $preset->value }}" :selected="$period->preset === $preset">{{ $preset->label() }}</flux:select.option>
        @endforeach
        <flux:select.option value="custom" :selected="$period->preset === \App\Enums\PeriodPreset::Custom">Custom…</flux:select.option>
    </flux:select>

    <flux:input name="period_start" label="Custom start" type="date" value="{{ $period->preset === \App\Enums\PeriodPreset::Custom ? $period->start->format('Y-m-d') : '' }}" />
    <flux:input name="period_end" label="Custom end" type="date" value="{{ $period->preset === \App\Enums\PeriodPreset::Custom ? $period->end->format('Y-m-d') : '' }}" />

    <flux:button type="submit">Apply</flux:button>

    <span class="self-center text-sm text-zinc-500 dark:text-zinc-400">
        {{ $period->start->format('M j, Y') }} – {{ $period->end->format('M j, Y') }}
    </span>
</form>
