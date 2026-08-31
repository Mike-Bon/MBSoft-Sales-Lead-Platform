<?php

namespace App\Enums;

enum PerformanceImportType: string
{
    case Plan = 'plan';
    case Actual = 'actual';

    public function label(): string
    {
        return match ($this) {
            self::Plan => 'Phased budget (plan)',
            self::Actual => 'Operational actuals',
        };
    }
}
