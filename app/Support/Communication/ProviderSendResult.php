<?php

namespace App\Support\Communication;

use App\Enums\CommunicationFailureCode;

/**
 * The uniform result every provider (Gmail, WhatsApp Cloud API — and any
 * future channel) returns from a send attempt, so CommunicationService/
 * SendCommunicationJob never need to know which provider they're talking
 * to. Deliberately never carries raw provider tokens/secrets — only
 * whatever non-sensitive identifiers and metadata are safe to persist on
 * the Communication row.
 */
final readonly class ProviderSendResult
{
    /**
     * @param  array<string, mixed>  $metadata  Safe-to-store provider
     *                                          response fragments (e.g. Gmail message id, WhatsApp
     *                                          message status) — never tokens, never full raw payloads
     *                                          that might carry a recipient's private message content
     *                                          twice over.
     */
    private function __construct(
        public bool $success,
        public ?string $providerMessageId,
        public ?string $providerThreadId,
        public ?CommunicationFailureCode $failureCode,
        public ?string $failureReason,
        public array $metadata = [],
    ) {}

    public static function success(?string $providerMessageId, ?string $providerThreadId = null, array $metadata = []): self
    {
        return new self(true, $providerMessageId, $providerThreadId, null, null, $metadata);
    }

    public static function failure(CommunicationFailureCode $code, string $reason, array $metadata = []): self
    {
        return new self(false, null, null, $code, $reason, $metadata);
    }
}
