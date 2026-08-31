<?php

namespace App\Enums;

/**
 * An internal branch / reporting-location's lifecycle. Same minimal
 * shape as TeamStatus. A reporting unit is NEVER a customer/prospect —
 * it is a slice of the seller's own operational structure used only for
 * fiscal performance reporting.
 */
enum ReportingUnitStatus: string
{
    case Active = 'active';
    case Inactive = 'inactive';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Inactive => 'Inactive',
        };
    }
}
