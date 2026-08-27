<?php

namespace App\Support\CostToServe;

/**
 * Phase 12 STEP 10: a period-over-period change in one metric,
 * handling a zero/near-zero previous value explicitly rather than
 * producing Infinity/NaN or a misleadingly huge percentage.
 *
 * States:
 *   - previous > 0: ordinary percent change.
 *   - previous == 0, current > 0: "new" — no previous baseline to
 *     compare against, so no percentage is reported, only the two raw
 *     values.
 *   - previous == 0, current == 0: "unchanged" — nothing happened in
 *     either period.
 */
final readonly class MetricChange
{
    public function __construct(
        public float $previous,
        public float $current,
        public ?float $percent,
        public string $state,
    ) {}

    public static function compute(float $previous, float $current): self
    {
        if (abs($previous) < 0.005) {
            return new self(
                previous: $previous,
                current: $current,
                percent: null,
                state: $current > 0 ? 'new' : 'unchanged',
            );
        }

        $percent = (($current - $previous) / abs($previous)) * 100;

        return new self(
            previous: $previous,
            current: $current,
            percent: round($percent, 1),
            state: match (true) {
                $percent > 0.05 => 'increased',
                $percent < -0.05 => 'decreased',
                default => 'unchanged',
            },
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'previous' => round($this->previous, 2),
            'current' => round($this->current, 2),
            'percent_change' => $this->percent,
            'state' => $this->state,
        ];
    }
}
