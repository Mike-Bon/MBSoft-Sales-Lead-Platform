<?php

namespace App\Http\Requests\Communication;

use App\Models\WhatsAppBusinessNumber;
use Illuminate\Foundation\Http\FormRequest;

/**
 * STEP 11: registering a business-owned WhatsApp number in the CRM.
 * This only records the number's identifiers — the actual Meta-side
 * registration (verifying the number, generating the phone_number_id)
 * happens in Meta Business Manager and is out of this application's
 * control; see docs/COMMUNICATIONS.md.
 */
class StoreWhatsAppNumberRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', WhatsAppBusinessNumber::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'display_name' => ['required', 'string', 'max:255'],
            'phone_number' => ['required', 'string', 'max:32'],
            'phone_number_id' => ['required', 'string', 'max:255', 'unique:whatsapp_business_numbers,phone_number_id'],
            'waba_id' => ['nullable', 'string', 'max:255'],
            'team_id' => ['nullable', 'integer', 'exists:teams,id'],
        ];
    }
}
