<?php

namespace App\Support\Ai;

use App\Enums\AgentInteractionStatus;

/**
 * What Agent::respond() returns to its caller. `toolsUsed` is name +
 * arguments only (STEP 33/35: a concise activity status for the UI and
 * a sanitized audit trail — never hidden chain-of-thought, never raw
 * tool *output*). `draft` is the structured payload from a
 * draft_email/draft_whatsapp tool call, if the most recent one produced
 * one — surfaced separately so the UI can render a draft preview card.
 */
final readonly class AgentResponse
{
    /**
     * @param  list<array{name: string, arguments: array<string, mixed>}>  $toolsUsed
     * @param  array<string, mixed>|null  $draft
     * @param  array{input_tokens: int, output_tokens: int}  $usage
     */
    public function __construct(
        public AgentInteractionStatus $status,
        public ?string $text,
        public array $toolsUsed,
        public ?array $draft,
        public array $usage,
    ) {}
}
