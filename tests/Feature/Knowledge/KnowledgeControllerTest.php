<?php

namespace Tests\Feature\Knowledge;

use App\Enums\KnowledgeStatus;
use App\Enums\KnowledgeVisibility;
use App\Jobs\Knowledge\ProcessKnowledgeDocumentVersionJob;
use App\Models\KnowledgeDocument;
use App\Models\KnowledgeDocumentVersion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Phase 10 STEP 31: HTTP-level authorization and validation for
 * knowledge administration — authoring is Manager-only, viewing follows
 * the visibility matrix, and duplicate/invalid submissions fail with
 * validation errors rather than a raw exception.
 */
class KnowledgeControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_guest_is_redirected_to_login(): void
    {
        $this->get('/knowledge')->assertRedirect('/login');
    }

    public function test_a_manager_can_create_a_document(): void
    {
        Queue::fake();
        $manager = User::factory()->manager()->create();

        $this->actingAs($manager)->post('/knowledge', [
            'title' => 'Refund Policy',
            'type' => 'policy',
            'visibility' => 'organisation',
            'raw_content' => str_repeat('Refunds are issued within 14 days. ', 3),
        ])->assertRedirect();

        $document = KnowledgeDocument::firstOrFail();
        $this->assertSame('Refund Policy', $document->title);
        $this->assertSame($manager->id, $document->created_by);

        Queue::assertPushed(ProcessKnowledgeDocumentVersionJob::class);
    }

    public function test_a_team_head_cannot_create_a_document(): void
    {
        $teamHead = User::factory()->teamHead()->create();

        $this->actingAs($teamHead)->post('/knowledge', [
            'title' => 'Refund Policy',
            'type' => 'policy',
            'visibility' => 'organisation',
            'raw_content' => str_repeat('Refunds are issued within 14 days. ', 3),
        ])->assertForbidden();

        $this->assertDatabaseCount('knowledge_documents', 0);
    }

    public function test_a_team_head_cannot_view_the_create_form(): void
    {
        $teamHead = User::factory()->teamHead()->create();

        $this->actingAs($teamHead)->get('/knowledge/create')->assertForbidden();
    }

    public function test_creating_a_document_requires_the_documented_fields(): void
    {
        $manager = User::factory()->manager()->create();

        $this->actingAs($manager)->post('/knowledge', [])
            ->assertSessionHasErrors(['title', 'type', 'visibility', 'raw_content']);
    }

    public function test_uploading_a_duplicate_of_active_content_fails_with_a_validation_error(): void
    {
        Queue::fake();
        $manager = User::factory()->manager()->create();
        $content = str_repeat('Duplicate policy content. ', 3);

        $this->actingAs($manager)->post('/knowledge', [
            'title' => 'First',
            'type' => 'policy',
            'visibility' => 'organisation',
            'raw_content' => $content,
        ]);

        $this->actingAs($manager)->post('/knowledge', [
            'title' => 'Second',
            'type' => 'policy',
            'visibility' => 'organisation',
            'raw_content' => $content,
        ])->assertSessionHasErrors('raw_content');

        $this->assertSame(1, KnowledgeDocument::count());
    }

    public function test_a_team_head_can_view_an_organisation_wide_document_but_not_a_manager_only_one(): void
    {
        $teamHead = User::factory()->teamHead()->create();
        $orgDocument = KnowledgeDocument::factory()->create(['visibility' => KnowledgeVisibility::Organisation]);
        $managerDocument = KnowledgeDocument::factory()->create(['visibility' => KnowledgeVisibility::Manager]);

        $this->actingAs($teamHead)->get("/knowledge/{$orgDocument->id}")->assertOk();
        $this->actingAs($teamHead)->get("/knowledge/{$managerDocument->id}")->assertForbidden();
    }

    public function test_the_index_only_lists_documents_the_viewer_is_authorized_to_see(): void
    {
        $teamHead = User::factory()->teamHead()->create();
        KnowledgeDocument::factory()->create(['visibility' => KnowledgeVisibility::Organisation, 'title' => 'Visible Org Policy']);
        KnowledgeDocument::factory()->create(['visibility' => KnowledgeVisibility::Manager, 'title' => 'Hidden Manager Policy']);

        $response = $this->actingAs($teamHead)->get('/knowledge');

        $response->assertOk()->assertSee('Visible Org Policy')->assertDontSee('Hidden Manager Policy');
    }

    public function test_a_manager_can_submit_a_new_version(): void
    {
        Queue::fake();
        $manager = User::factory()->manager()->create();
        $document = KnowledgeDocument::factory()->create(['created_by' => $manager->id]);
        KnowledgeDocumentVersion::factory()->create(['knowledge_document_id' => $document->id, 'version_number' => 1]);

        $this->actingAs($manager)->post("/knowledge/{$document->id}/versions", [
            'raw_content' => str_repeat('Updated policy content. ', 3),
        ])->assertRedirect(route('knowledge.show', $document));

        $this->assertSame(2, $document->versions()->count());
    }

    public function test_a_team_head_cannot_submit_a_new_version(): void
    {
        $teamHead = User::factory()->teamHead()->create();
        $document = KnowledgeDocument::factory()->create(['visibility' => KnowledgeVisibility::Organisation]);

        $this->actingAs($teamHead)->post("/knowledge/{$document->id}/versions", [
            'raw_content' => str_repeat('Updated policy content. ', 3),
        ])->assertForbidden();
    }

    public function test_a_manager_can_archive_an_active_version(): void
    {
        $manager = User::factory()->manager()->create();
        $document = KnowledgeDocument::factory()->create();
        $version = KnowledgeDocumentVersion::factory()->create(['knowledge_document_id' => $document->id, 'status' => KnowledgeStatus::Active]);
        $document->current_version_id = $version->id;
        $document->save();

        $this->actingAs($manager)->post("/knowledge/{$document->id}/versions/{$version->id}/archive")
            ->assertRedirect(route('knowledge.show', $document));

        $this->assertSame(KnowledgeStatus::Archived, $version->fresh()->status);
    }

    public function test_archiving_a_version_that_does_not_belong_to_the_document_404s(): void
    {
        $manager = User::factory()->manager()->create();
        $document = KnowledgeDocument::factory()->create();
        $otherDocument = KnowledgeDocument::factory()->create();
        $version = KnowledgeDocumentVersion::factory()->create(['knowledge_document_id' => $otherDocument->id]);

        $this->actingAs($manager)->post("/knowledge/{$document->id}/versions/{$version->id}/archive")
            ->assertNotFound();
    }

    public function test_a_manager_can_delete_a_document(): void
    {
        $manager = User::factory()->manager()->create();
        $document = KnowledgeDocument::factory()->create();

        $this->actingAs($manager)->delete("/knowledge/{$document->id}")
            ->assertRedirect(route('knowledge.index'));

        $this->assertDatabaseMissing('knowledge_documents', ['id' => $document->id]);
    }

    public function test_a_team_head_cannot_delete_a_document(): void
    {
        $teamHead = User::factory()->teamHead()->create();
        $document = KnowledgeDocument::factory()->create(['visibility' => KnowledgeVisibility::Organisation]);

        $this->actingAs($teamHead)->delete("/knowledge/{$document->id}")->assertForbidden();
        $this->assertDatabaseHas('knowledge_documents', ['id' => $document->id]);
    }
}
