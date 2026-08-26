<?php

namespace App\Support\Ai;

/**
 * The uniform result every LlmProvider returns for one completion call —
 * AssistantService and every test depend only on this shape, never on a
 * specific provider's raw response format.
 */
final readonly class AiCompletionResult
{
    /**
     * @param  list<ToolCall>  $toolCalls
     * @param  array{input_tokens: int, output_tokens: int}  $usage
     */
    public function __construct(
        public ?string $text,
        public array $toolCalls,
        public string $stopReason,
        public array $usage,
    ) {}

    public function hasToolCalls(): bool
    {
        return $this->toolCalls !== [];
    }
}
