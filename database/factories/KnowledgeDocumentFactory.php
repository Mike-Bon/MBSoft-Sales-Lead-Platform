<?php

namespace Database\Factories;

use App\Enums\KnowledgeType;
use App\Enums\KnowledgeVisibility;
use App\Models\KnowledgeDocument;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<KnowledgeDocument>
 */
class KnowledgeDocumentFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'title' => fake()->sentence(4),
            'type' => KnowledgeType::Policy,
            'visibility' => KnowledgeVisibility::Organisation,
            'team_id' => null,
            'created_by' => User::factory(),
            'current_version_id' => null,
        ];
    }

    public function manager(): static
    {
        return $this->state(fn (array $attributes) => ['visibility' => KnowledgeVisibility::Manager]);
    }

    public function team(): static
    {
        return $this->state(fn (array $attributes) => ['visibility' => KnowledgeVisibility::Team]);
    }
}
