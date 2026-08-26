<?php

namespace App\Enums;

enum LeadStatus: string
{
    case New = 'new';
    case Contacted = 'contacted';
    case Qualified = 'qualified';
    case Disqualified = 'disqualified';
    case Converted = 'converted';

    public function label(): string
    {
        return match ($this) {
            self::New => 'New',
            self::Contacted => 'Contacted',
            self::Qualified => 'Qualified',
            self::Disqualified => 'Disqualified',
            self::Converted => 'Converted',
        };
    }

    /**
     * Terminal statuses: a lead here is done moving through the pipeline
     * on its own (Converted leads live on via their Opportunity).
     */
    public function isTerminal(): bool
    {
        return in_array($this, [self::Disqualified, self::Converted], true);
    }
}
