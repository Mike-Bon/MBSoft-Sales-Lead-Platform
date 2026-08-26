<?php

namespace App\Enums;

/**
 * Not every channel supports every status (STEP 5) — document exactly
 * what each provider actually gives us, rather than pretending a status
 * exists where it doesn't:
 *
 *   Email (Gmail API):    Queued -> Sending -> Sent -> Failed.
 *     Gmail's send API confirms acceptance (Sent) but does not provide
 *     delivered/read receipts through any officially supported,
 *     non-invasive mechanism, so Delivered/Read are never set for email
 *     in this system.
 *
 *   WhatsApp (Cloud API):  Queued -> Sending -> Sent -> Delivered -> Read
 *                          (or -> Failed at any point).
 *     Meta's webhook status callbacks genuinely provide sent/delivered/
 *     read/failed events for outbound messages, so the full lifecycle
 *     is meaningful here.
 *
 *   Inbound communications (either channel) are recorded directly as
 *   Delivered — inbound messages that reached this system by definition
 *   already arrived; the intermediate states only describe our own
 *   outbound send lifecycle.
 */
enum CommunicationStatus: string
{
    case Queued = 'queued';
    case Sending = 'sending';
    case Sent = 'sent';
    case Delivered = 'delivered';
    case Read = 'read';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Queued => 'Queued',
            self::Sending => 'Sending',
            self::Sent => 'Sent',
            self::Delivered => 'Delivered',
            self::Read => 'Read',
            self::Failed => 'Failed',
        };
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Delivered, self::Read, self::Failed], true);
    }
}
