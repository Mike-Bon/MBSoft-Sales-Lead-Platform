<?php

namespace App\Enums;

enum EmailAccountStatus: string
{
    case Connected = 'connected';
    case Disconnected = 'disconnected';
    case NeedsReauth = 'needs_reauth';

    public function label(): string
    {
        return match ($this) {
            self::Connected => 'Connected',
            self::Disconnected => 'Disconnected',
            self::NeedsReauth => 'Needs reconnection',
        };
    }
}
