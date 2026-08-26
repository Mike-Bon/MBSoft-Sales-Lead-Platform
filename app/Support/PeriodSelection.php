<?php

namespace App\Support;

use App\Enums\PeriodPreset;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Resolves a request's period selection (STEP 11) into an explicit
 * [start, end] date range exactly once, in one place — every controller
 * that offers a period selector (DashboardController,
 * PerformanceController, TeamPerformanceController) uses this instead of
 * each parsing query parameters independently.
 */
final readonly class PeriodSelection
{
    public function __construct(
        public PeriodPreset $preset,
        public Carbon $start,
        public Carbon $end,
    ) {}

    public static function fromRequest(Request $request): self
    {
        $request->validate([
            'period' => ['nullable', 'string'],
            'period_start' => ['nullable', 'date'],
            'period_end' => ['nullable', 'date', 'after_or_equal:period_start'],
        ]);

        if ($request->filled('period_start') && $request->filled('period_end')) {
            return new self(
                PeriodPreset::Custom,
                Carbon::parse($request->query('period_start'))->startOfDay(),
                Carbon::parse($request->query('period_end'))->startOfDay(),
            );
        }

        $preset = PeriodPreset::tryFrom((string) $request->query('period')) ?? PeriodPreset::CurrentMonth;
        [$start, $end] = $preset->range();

        return new self($preset, $start, $end);
    }
}
