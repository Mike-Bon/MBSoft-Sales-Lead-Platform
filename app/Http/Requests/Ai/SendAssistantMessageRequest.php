<?php

namespace App\Http\Requests\Ai;

use Illuminate\Foundation\Http\FormRequest;

/**
 * STEP 37: authenticated (route middleware), rate-limited (route
 * throttle), and length-bounded — never an unbounded request body sent
 * to the LLM provider.
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
        ];
    }
}
