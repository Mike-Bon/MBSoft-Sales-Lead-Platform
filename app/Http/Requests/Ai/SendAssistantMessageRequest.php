<?php

namespace App\Http\Requests\Ai;

use App\Enums\AgentIdentifier;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * STEP 37: authenticated (route middleware), rate-limited (route
 * throttle), and length-bounded — never an unbounded request body sent
 * to the LLM provider. `agent` (STEP 17 explicit agent selection) is
 * optional — omitted or "auto" means AgentRouter decides.
 */
class SendAssistantMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'message' => ['required', 'string', 'max:'.(int) config('services.ai.max_message_length', 2000)],
            'agent' => ['nullable', Rule::enum(AgentIdentifier::class)],
        ];
    }
}
