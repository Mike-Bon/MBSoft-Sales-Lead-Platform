<?php

namespace App\Http\Requests\Communication;

use Illuminate\Foundation\Http\FormRequest;

/**
 * STEP 16/20: validates the shape of a compose-email submission only.
 * It deliberately does NOT decide whether the actor may send from a
 * given account or touch a given CRM record — that authorization is
 * re-derived server-side by CommunicationService/CommunicationAuthorizer,
 * never trusted from this request's input.
 */
class SendEmailRequest extends FormRequest
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
            'recipient' => ['required', 'email', 'max:255'],
            'template_id' => ['nullable', 'integer', 'exists:message_templates,id'],
            'subject' => ['nullable', 'required_without:template_id', 'string', 'max:255'],
            'body' => ['nullable', 'required_without:template_id', 'string'],
            'organization_id' => ['nullable', 'integer', 'exists:organizations,id'],
            'contact_id' => ['nullable', 'integer', 'exists:contacts,id'],
            'lead_id' => ['nullable', 'integer', 'exists:leads,id'],
            'opportunity_id' => ['nullable', 'integer', 'exists:opportunities,id'],
            'confirm' => ['accepted'],
        ];
    }
}
