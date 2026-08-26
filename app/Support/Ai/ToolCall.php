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
     */
    public function __construct(
        public string $id,
        public string $name,
        public array $arguments,
    ) {}
}
