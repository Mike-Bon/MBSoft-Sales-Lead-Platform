<?php

namespace App\Support\Ai;

/**
 * One tool invocation the model asked for — a provider-issued id (used
 * to correlate the eventual tool_result back to this call), the tool
 * name, and its arguments exactly as the model supplied them (untrusted
 * until the tool itself validates/authorizes them).
 */
final readonly class ToolCall
{
    /**
     * @param  array<string, mixed>  $arguments
     * @param  string|null  $providerSignature  Opaque, provider-specific
     *                                          reasoning-continuity token. Some providers (Gemini 3+) return one
     *                                          on the model turn that produced this call and reject the next
     *                                          request unless it is replayed verbatim in the same part. It is
     *                                          never decoded, mutated, shown to users, logged, exposed to tools,
     *                                          or written to the audit trail — it only circulates between the
     *                                          provider adapter and its API. Distinct from $id (correlation);
     *                                          the two are never conflated. Providers without the concept
     *                                          (e.g. Anthropic) leave it null.
     */
    public function __construct(
        public string $id,
        public string $name,
        public array $arguments,
        public ?string $providerSignature = null,
    ) {}
}
