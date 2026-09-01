<?php

namespace App\Support\Performance;

use App\Enums\ActualLineChangeType;
use App\Models\PerformanceActualLine;
use App\Models\PerformanceActualLineRevision;

/**
 * The outcome of one authoritative write to a performance_actual_lines
 * row. `changeType === Unchanged` means nothing was written and no
 * revision was recorded (a no-op submission).
 */
final readonly class ActualLineWriteResult
{
    public function __construct(
        public PerformanceActualLine $line,
        public ActualLineChangeType $changeType,
        public ?PerformanceActualLineRevision $revision,
        public ?float $previousRevenue,
        public ?float $previousUnits,
    ) {}

    public function wrote(): bool
    {
        return $this->changeType !== ActualLineChangeType::Unchanged;
    }
}
