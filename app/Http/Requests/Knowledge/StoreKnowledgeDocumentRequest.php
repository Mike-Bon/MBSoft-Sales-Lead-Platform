<?php

namespace App\Http\Requests\Knowledge;

use App\Enums\KnowledgeType;
use App\Enums\KnowledgeVisibility;
use App\Models\KnowledgeDocument;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreKnowledgeDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', KnowledgeDocument::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::enum(KnowledgeType::class)],
            'visibility' => ['required', Rule::enum(KnowledgeVisibility::class)],
            // Only meaningful when visibility=team (see KnowledgeDocumentService,
            // which discards it otherwise) — still validated here so a
            // malformed team id fails cleanly rather than silently.
            'team_id' => ['nullable', 'integer', 'exists:teams,id', 'required_if:visibility,team'],
            // Plain text/Markdown only for this phase — see docs/KNOWLEDGE.md
            // on why file-format ingestion (PDF/DOCX) is deferred.
            'raw_content' => ['required', 'string', 'min:20', 'max:20000'],
            'effective_from' => ['nullable', 'date'],
            'effective_until' => ['nullable', 'date', 'after_or_equal:effective_from'],
        ];
    }
}
