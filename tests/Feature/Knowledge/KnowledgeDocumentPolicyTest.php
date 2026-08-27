<?php

namespace Tests\Feature\Knowledge;

use App\Enums\KnowledgeVisibility;
use App\Models\KnowledgeDocument;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 10 STEP 25/31: the admin-UI authorization matrix — deliberately
 * mirrors KnowledgeSearchService's own visibility rule (see that
 * class's docblock for why it's not simply delegated to instead).
 */
class KnowledgeDocumentPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_a_manager_can_create_update_or_delete_a_document(): void
    {
        $manager = User::factory()->manager()->create();
        $teamHead = User::factory()->teamHead()->create();
        $document = KnowledgeDocument::factory()->create();

        $this->assertTrue($manager->can('create', KnowledgeDocument::class));
        $this->assertTrue($manager->can('update', $document));
        $this->assertTrue($manager->can('delete', $document));

        $this->assertFalse($teamHead->can('create', KnowledgeDocument::class));
        $this->assertFalse($teamHead->can('update', $document));
        $this->assertFalse($teamHead->can('delete', $document));
    }

    public function test_everyone_may_view_an_organisation_wide_document(): void
    {
        $document = KnowledgeDocument::factory()->create(['visibility' => KnowledgeVisibility::Organisation]);

        foreach ([User::factory()->manager()->create(), User::factory()->teamHead()->create(), User::factory()->teamMember()->create()] as $user) {
            $this->assertTrue($user->can('view', $document));
        }
    }

    public function test_only_a_manager_may_view_a_manager_only_document(): void
    {
        $document = KnowledgeDocument::factory()->create(['visibility' => KnowledgeVisibility::Manager]);

        $this->assertTrue(User::factory()->manager()->create()->can('view', $document));
        $this->assertFalse(User::factory()->teamHead()->create()->can('view', $document));
        $this->assertFalse(User::factory()->teamMember()->create()->can('view', $document));
    }

    public function test_a_team_scoped_document_is_visible_only_to_that_team_and_the_manager(): void
    {
        $teamA = Team::factory()->create();
        $teamB = Team::factory()->create();
        $document = KnowledgeDocument::factory()->create(['visibility' => KnowledgeVisibility::Team, 'team_id' => $teamA->id]);

        $this->assertTrue(User::factory()->manager()->create()->can('view', $document));
        $this->assertTrue(User::factory()->teamHead($teamA)->create()->can('view', $document));
        $this->assertTrue(User::factory()->teamMember($teamA)->create()->can('view', $document));
        $this->assertFalse(User::factory()->teamHead($teamB)->create()->can('view', $document));
    }
}
