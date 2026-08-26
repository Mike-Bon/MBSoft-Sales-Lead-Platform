<?php

namespace App\Enums;

/**
 * STEP 23's exact list — a controlled, closed set of user-facing failure
 * reasons. Raw provider exceptions/stack traces are never shown to the
 * end user; they are logged server-side only (see SendCommunicationJob)
 * and mapped to one of these before being stored on the Communication
 * record.
 */
enum CommunicationFailureCode: string
{
    case EmailFailed = 'email_failed';
    case WhatsAppFailed = 'whatsapp_failed';
    case AuthenticationError = 'authentication_error';
    case InvalidRecipient = 'invalid_recipient';
    case ProviderError = 'provider_error';
    case RateLimited = 'rate_limited';
    case TemporaryNetworkError = 'temporary_network_error';

    public function label(): string
    {
        return match ($this) {
            self::EmailFailed => 'Email delivery failed',
            self::WhatsAppFailed => 'WhatsApp delivery failed',
            self::AuthenticationError => 'The sending account needs to be reconnected',
            self::InvalidRecipient => 'The recipient address/number is invalid',
            self::ProviderError => 'The messaging provider reported an error',
            self::RateLimited => 'Sending was temporarily rate-limited — it will be retried',
            self::TemporaryNetworkError => 'A temporary network error occurred — it will be retried',
        };
    }

    /**
     * Whether SendCommunicationJob should retry after this failure, or
     * mark it permanently failed (STEP 21/23).
     */
    public function isRetryable(): bool
    {
        return in_array($this, [self::RateLimited, self::TemporaryNetworkError], true);
    }
}
