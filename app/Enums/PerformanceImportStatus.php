<?php

namespace App\Enums;

enum PerformanceImportStatus: string
{
    case Validating = 'validating';
    case Completed = 'completed';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Validating => 'Validating',
            self::Completed => 'Completed',
            self::Failed => 'Failed',
        };
    }
}
