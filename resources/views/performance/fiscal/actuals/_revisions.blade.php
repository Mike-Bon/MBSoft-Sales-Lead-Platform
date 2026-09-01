@php
    use App\Support\Money;
    use App\Enums\ActualLineChangeType;
    $fmt = fn ($v) => $v === null ? '—' : Money::format((float) $v, 'PHP', 2);
    $units = fn ($v) => $v === null ? '—' : number_format((float) $v, 2);
@endphp
@if ($revisions->isEmpty())
    <p class="text-sm text-zinc-500">No changes recorded yet.</p>
@else
    <flux:table class="mb-4">
        <flux:table.columns>
            <flux:table.column>When</flux:table.column>
            <flux:table.column>Reporting unit</flux:table.column>
            <flux:table.column>Fiscal month</flux:table.column>
            <flux:table.column>Revenue</flux:table.column>
            <flux:table.column>Units</flux:table.column>
            <flux:table.column>By</flux:table.column>
            <flux:table.column>Source</flux:table.column>
        </flux:table.columns>
        <flux:table.rows>
            @foreach ($revisions as $r)
                <flux:table.row>
                    <flux:table.cell class="whitespace-nowrap text-sm">{{ $r->created_at?->format('Y-m-d H:i') }}</flux:table.cell>
                    <flux:table.cell>{{ $r->reportingUnit?->name ?? '—' }} <span class="text-xs text-zinc-400">{{ $r->team?->name }}</span></flux:table.cell>
                    <flux:table.cell>FY{{ $r->fiscal_year }} · month {{ $r->period_month }}</flux:table.cell>
                    <flux:table.cell>
                        @if ($r->change_type === ActualLineChangeType::Created)
                            <flux:badge color="green" size="sm">new</flux:badge> {{ $fmt($r->new_revenue) }}
                        @else
                            <span class="text-zinc-400 line-through">{{ $fmt($r->previous_revenue) }}</span> → {{ $fmt($r->new_revenue) }}
                        @endif
                    </flux:table.cell>
                    <flux:table.cell>
                        @if ($r->change_type === ActualLineChangeType::Created)
                            {{ $units($r->new_units) }}
                        @else
                            <span class="text-zinc-400 line-through">{{ $units($r->previous_units) }}</span> → {{ $units($r->new_units) }}
                        @endif
                    </flux:table.cell>
                    <flux:table.cell class="text-sm">{{ $r->changedBy?->name ?? 'system (CLI)' }}</flux:table.cell>
                    <flux:table.cell class="text-sm">
                        {{ $r->channel->label() }}
                        @if ($r->import)
                            <span class="block text-xs text-zinc-400" title="{{ $r->import->file_sha256 }}">
                                {{ $r->import->original_filename ?? 'batch #'.$r->import->id }}
                                @if ($r->import->file_sha256) · sha256 {{ substr($r->import->file_sha256, 0, 12) }}… @endif
                            </span>
                        @endif
                        @if ($r->reason)
                            <span class="block text-xs italic text-zinc-500">“{{ $r->reason }}”</span>
                        @endif
                    </flux:table.cell>
                </flux:table.row>
            @endforeach
        </flux:table.rows>
    </flux:table>
    @if (($paginated ?? false))
        {{ $revisions->links() }}
    @endif
@endif
