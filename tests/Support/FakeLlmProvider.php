<?php

namespace Tests\Support;

use App\Contracts\Ai\LlmProvider;
use App\Support\Ai\AiCompletionResult;
use App\Support\Ai\ToolCall;
use App\Support\Ai\ToolDefinition;

/**
 * A deterministic stand-in for a real LLM (STEP 28: automated tests must
 * never make real provider calls). Given a fixed sequence of
 * AiCompletionResult "turns", it plays them back in order — the last one
 * repeats if the agent calls complete() more times than there are queued
 * turns (used by the tool-call-limit tests, where the fake keeps
 * returning a tool call forever).
 *
 * Also records every call it received, so a test can assert on exactly
 * what system prompt / message history / tool definitions the agent
 * built — this is what makes the prompt-injection tests meaningful: a
 * test can make the fake "model" attempt something malicious (call an
 * unregistered tool, ask for another team's data) and assert the
 * surrounding system still prevented the effect, without needing a real,
 * non-deterministic LLM to behave a particular way.
 */
class FakeLlmProvider implements LlmProvider
{
    /**
     * @var list<AiCompletionResult>
     */
    private array $turns;

    private int $index = 0;

    /**
     * @var list<array{system: string, messages: array<int, array<string, mixed>>, tools: list<ToolDefinition>}>
     */
    public array $calls = [];

    /**
     * @param  list<AiCompletionResult>  $turns
     */
    public function __construct(array $turns)
    {
        $this->turns = $turns;
    }

    public function complete(string $systemPrompt, array $messages, array $tools): AiCompletionResult
    {
        $this->calls[] = ['system' => $systemPrompt, 'messages' => $messages, 'tools' => $tools];

        $turn = $this->turns[$this->index] ?? end($this->turns);
        $this->index++;

        return $turn;
    }

    public static function text(string $text): AiCompletionResult
    {
        return new AiCompletionResult($text, [], 'end_turn', ['input_tokens' => 10, 'output_tokens' => 10]);
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    public static function toolCall(string $name, array $arguments, ?string $id = null): AiCompletionResult
    {
        return new AiCompletionResult(
            null,
            [new ToolCall($id ?? 'call_'.$name, $name, $arguments)],
            'tool_use',
            ['input_tokens' => 10, 'output_tokens' => 10],
        );
    }
}
