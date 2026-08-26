<?php

namespace App\Http\Requests\Communication;

use App\Enums\CommunicationChannel;
use App\Models\MessageTemplate;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreMessageTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', MessageTemplate::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'channel' => ['required', Rule::enum(CommunicationChannel::class)],
            'subject' => ['nullable', 'string', 'max:255'],
            'body' => ['required', 'string', 'max:4096'],
            // Only meaningful for a Manager (see MessageTemplateController):
            // a non-Manager's template is always scoped to their own team
            // regardless of this input.
            'team_id' => ['nullable', 'integer', 'exists:teams,id'],
        ];
    }
}
