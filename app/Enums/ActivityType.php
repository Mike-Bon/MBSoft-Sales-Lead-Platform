<?php

namespace App\Enums;

/**
 * A single, unified activity type list rather than separate tables per
 * channel. Call/Email/WhatsApp/Meeting/Note/FollowUp/Proposal/Other only
 * record that an activity of this kind happened — Gmail/WhatsApp
 * integrations that would actually send/receive messages are later
 * phases; this is just the data model for recording the event.
 */
enum ActivityType: string
{
    case Call = 'call';
    case Email = 'email';
    case WhatsApp = 'whatsapp';
    case Meeting = 'meeting';
    case Note = 'note';
    case FollowUp = 'follow_up';
    case Proposal = 'proposal';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Call => 'Call',
            self::Email => 'Email',
            self::WhatsApp => 'WhatsApp',
            self::Meeting => 'Meeting',
            self::Note => 'Note',
            self::FollowUp => 'Follow-up',
            self::Proposal => 'Proposal',
            self::Other => 'Other',
        };
    }
}
