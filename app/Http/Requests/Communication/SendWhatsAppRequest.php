<?php

namespace App\Http\Requests\Communication;

use Illuminate\Foundation\Http\FormRequest;

/**
 * STEP 16/20: shape validation only — see SendEmailRequest's docblock.
 * whatsapp_number_id is required input (the composer lets the user pick
 * which connected number to send from when more than one is available
 * to them), but which numbers they're allowed to pick from at all is
 * enforced server-side by CommunicationAuthorizer, never here.
 */
class SendWhatsAppRequest extends FormRequest
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
            'whatsapp_number_id' => ['required', 'integer', 'exists:whatsapp_business_numbers,id'],
            'recipient' => ['required', 'string', 'regex:/^\+[1-9][0-9]{6,14}$/'],
            'template_id' => ['nullable', 'integer', 'exists:message_templates,id'],
            'body' => ['nullable', 'required_without:template_id', 'string', 'max:4096'],
            'organization_id' => ['nullable', 'integer', 'exists:organizations,id'],
            'contact_id' => ['nullable', 'integer', 'exists:contacts,id'],
            'lead_id' => ['nullable', 'integer', 'exists:leads,id'],
            'opportunity_id' => ['nullable', 'integer', 'exists:opportunities,id'],
            'confirm' => ['accepted'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'recipient.regex' => 'Enter the recipient in E.164 format, e.g. +15551234567.',
        ];
    }
}
