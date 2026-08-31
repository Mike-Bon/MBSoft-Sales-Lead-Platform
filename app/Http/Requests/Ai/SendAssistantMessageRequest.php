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
            // V2.0.3: a per-page-render UUID (hidden field). Only the
            // Market Intelligence path uses it — as the idempotency
            // token that makes a browser re-POST (refresh/back/double-
            // click) hit the same ProspectResearchRun instead of
            // dispatching a second research job. Absent/!uuid is
            // tolerated (falls back to a fresh run).
            'submission_id' => ['nullable', 'uuid'],
            'agent' => [
                'nullable',
                Rule::enum(AgentIdentifier::class),
                // Phase 12/12A: never trust the client to only offer an
                // eligible agent in the dropdown — re-checked here
                // server-side regardless of what was actually
                // submitted. Deliberately generic wording: for
                // Cost-to-Serve this can fail because of role OR
                // because the global feature switch is off, and this
                // message must not distinguish which (STEP 11: never
                // reveal the switch's state to someone who isn't
                // role-authorized anyway).
                function (string $attribute, mixed $value, \Closure $fail) {
                    $agent = AgentIdentifier::tryFrom((string) $value);

                    if ($agent !== null && $this->user() !== null && ! $agent->isAvailableTo($this->user())) {
                        $fail('That assistant is not currently available.');
                    }
                },
            ],
        ];
    }
}
