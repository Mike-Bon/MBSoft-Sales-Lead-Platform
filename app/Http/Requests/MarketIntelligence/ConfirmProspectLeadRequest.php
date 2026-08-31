<?php

namespace App\Http\Requests\MarketIntelligence;

use App\Models\Lead;
use App\Models\ProspectLeadProposal;
use Illuminate\Foundation\Http\FormRequest;

/**
 * V2.5 (spec §14): every final CRM field is validated server-side here —
 * never trusted from a hidden field or the browser. The confirmation is
 * authorised only when the actor may use the proposal AND passes the
 * normal V1 `create` Lead policy (Market Intelligence eligibility is not
 * sufficient on its own — spec §15).
 */
class ConfirmProspectLeadRequest extends FormRequest
{
    public function authorize(): bool
    {
        $proposal = $this->route('proposal');
        $user = $this->user();

        return $user !== null
            && $proposal instanceof ProspectLeadProposal
            && $user->can('confirm', $proposal)
            && $user->can('create', Lead::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $proposal = $this->route('proposal');
        $needsAck = $proposal instanceof ProspectLeadProposal && $proposal->duplicate_ack_required;

        return [
            'fingerprint' => ['required', 'string', 'size:64'],
            'business_name' => ['required', 'string', 'max:255'],
            'industry' => ['nullable', 'string', 'max:255'],
            'website' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:255'],
            'state_province' => ['nullable', 'string', 'max:255'],
            'country' => ['nullable', 'string', 'max:255'],
            'lead_description' => ['nullable', 'string', 'max:2000'],
            'acknowledge_possible_duplicate' => [
                $needsAck ? 'accepted' : 'nullable',
                'boolean',
            ],
        ];
    }

    /**
     * Never accept a client-supplied owner/team/status/score/duplicate
     * value — those are system-controlled (spec §13/§16).
     */
    protected function prepareForValidation(): void
    {
        $this->replace($this->safeInputOnly());
    }

    /**
     * @return array<string, mixed>
     */
    private function safeInputOnly(): array
    {
        return $this->only([
            'fingerprint', 'business_name', 'industry', 'website',
            'city', 'state_province', 'country', 'lead_description',
            'acknowledge_possible_duplicate',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function validatedFields(): array
    {
        $data = $this->validated();
        $data['acknowledge_possible_duplicate'] = $this->boolean('acknowledge_possible_duplicate');

        return $data;
    }
}
