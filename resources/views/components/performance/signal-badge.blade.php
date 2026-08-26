{{-- Renders an App\Enums\ManagementSignal — the signal itself is always
     computed by PerformanceSnapshot::managementSignal(), never here. --}}
@props(['signal'])

<flux:badge size="sm" :color="$signal->color()">{{ $signal->label() }}</flux:badge>
