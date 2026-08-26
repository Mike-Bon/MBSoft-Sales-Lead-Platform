<?php

namespace App\Http\Requests;

use App\Enums\ActivityType;
use App\Models\Activity;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreActivityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Activity::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'organization_id' => ['nullable', 'integer', 'exists:organizations,id'],
            'contact_id' => ['nullable', 'integer', 'exists:contacts,id'],
            'lead_id' => ['nullable', 'integer', 'exists:leads,id'],
            'opportunity_id' => ['nullable', 'integer', 'exists:opportunities,id'],
            'type' => ['required', Rule::enum(ActivityType::class)],
            'subject' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'occurred_at' => ['nullable', 'date'],
        ];
    }

    /**
     * At least one related record keeps an activity meaningful — it must
     * be logged against something.
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            if (! $this->filled('organization_id') && ! $this->filled('contact_id')
                && ! $this->filled('lead_id') && ! $this->filled('opportunity_id')) {
                $validator->errors()->add('lead_id', 'Select the organization, contact, lead, or opportunity this activity relates to.');
            }
        });
    }
}
