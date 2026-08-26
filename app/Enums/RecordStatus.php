<?php

namespace App\Enums;

/**
 * Generic active/inactive status shared by Organization and Contact.
 * Neither entity's status vocabulary is specified by the project
 * constitution beyond "status", so this stays deliberately minimal
 * rather than inventing an unrequested taxonomy (e.g. prospect/customer
 * stages) — extend later against real business input if needed.
 */
enum RecordStatus: string
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
