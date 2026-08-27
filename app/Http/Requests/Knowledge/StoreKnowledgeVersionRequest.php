<?php

namespace App\Http\Requests\Knowledge;

use App\Models\KnowledgeDocument;
use Illuminate\Foundation\Http\FormRequest;

class StoreKnowledgeVersionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $document = $this->route('knowledgeDocument');

        return $document instanceof KnowledgeDocument && ($this->user()?->can('update', $document) ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'raw_content' => ['required', 'string', 'min:20', 'max:20000'],
            'effective_from' => ['nullable', 'date'],
            'effective_until' => ['nullable', 'date', 'after_or_equal:effective_from'],
        ];
    }
}
