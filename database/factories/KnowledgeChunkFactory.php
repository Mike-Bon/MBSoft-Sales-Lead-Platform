<?php

namespace Database\Factories;

use App\Models\KnowledgeChunk;
use App\Models\KnowledgeDocumentVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<KnowledgeChunk>
 */
class KnowledgeChunkFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'knowledge_document_version_id' => KnowledgeDocumentVersion::factory(),
            'heading' => fake()->sentence(3),
            'section_order' => 0,
            'content' => fake()->paragraph(),
        ];
    }
}
