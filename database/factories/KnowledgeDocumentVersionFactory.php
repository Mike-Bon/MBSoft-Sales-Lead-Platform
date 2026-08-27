<?php

namespace Database\Factories;

use App\Enums\KnowledgeStatus;
use App\Models\KnowledgeDocument;
use App\Models\KnowledgeDocumentVersion;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<KnowledgeDocumentVersion>
 */
class KnowledgeDocumentVersionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $content = '# '.fake()->sentence(3)."\n\n".fake()->paragraphs(2, true);

        return [
            'knowledge_document_id' => KnowledgeDocument::factory(),
            'version_number' => 1,
            'status' => KnowledgeStatus::Active,
            'raw_content' => $content,
            'checksum' => hash('sha256', $content),
            'effective_from' => null,
            'effective_until' => null,
            'processing_error' => null,
            'uploaded_by' => User::factory(),
        ];
    }

    public function draft(): static
    {
        return $this->state(fn (array $attributes) => ['status' => KnowledgeStatus::Draft]);
    }

    public function archived(): static
    {
        return $this->state(fn (array $attributes) => ['status' => KnowledgeStatus::Archived]);
    }

    public function failed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => KnowledgeStatus::Failed,
            'processing_error' => 'No extractable content.',
        ]);
    }
}
