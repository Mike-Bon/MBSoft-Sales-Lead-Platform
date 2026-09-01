<?php

namespace App\Enums;

enum PerformanceImportStatus: string
{
    /** Web flow: uploaded + validated, awaiting the Manager's explicit confirmation. Nothing written. */
    case Previewing = 'previewing';
    case Validating = 'validating';
    case Completed = 'completed';
    case Failed = 'failed';
    /** Web flow: the Manager discarded the staged preview. Nothing written. */
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Previewing => 'Awaiting confirmation',
            self::Validating => 'Validating',
            self::Completed => 'Completed',
            self::Failed => 'Failed',
            self::Cancelled => 'Cancelled',
        };
    }
}
