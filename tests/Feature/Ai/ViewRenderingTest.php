<?php

namespace Tests\Feature\Ai;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Every assistant screen state actually renders — see
 * tests/Feature/Crm/ViewRenderingTest.php for why this exists as its
 * own file.
 */
class ViewRenderingTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_assistant_page_renders_with_an_empty_conversation(): void
    {
        $this->actingAs(User::factory()->create())->get('/assistant')->assertOk();
    }

    public function test_the_assistant_page_renders_with_a_conversation_and_tool_activity(): void
    {
        $user = User::factory()->create();
        session([
            'assistant.conversation' => [
                ['role' => 'user', 'content' => 'What is my pipeline?'],
                ['role' => 'assistant', 'content' => 'Your open pipeline is $50,000.', 'tools_used' => ['get_pipeline_summary'], 'status' => 'completed'],
            ],
        ]);

        $this->actingAs($user)->get('/assistant')->assertOk()->assertSee('get pipeline summary');
    }

    public function test_the_assistant_page_renders_a_successful_draft(): void
    {
        $user = User::factory()->create();
        session([
            'assistant.pending_draft' => [
                'draft' => true,
                'channel' => 'email',
                'recipient' => 'jamie@example.test',
                'subject' => 'Following up',
                'body' => 'Hi Jamie, checking in.',
                'contact_id' => 5,
            ],
        ]);

        $this->actingAs($user)->get('/assistant')->assertOk()->assertSee('jamie@example.test');
    }

    public function test_the_assistant_page_renders_a_declined_draft_reason(): void
    {
        $user = User::factory()->create();
        session([
            'assistant.pending_draft' => [
                'draft' => false,
                'reason' => 'no_connected_email_account',
                'message' => 'This user has no connected Gmail account.',
            ],
        ]);

        $this->actingAs($user)->get('/assistant')->assertOk()->assertSee('no connected Gmail account');
    }

    public function test_the_assistant_page_renders_a_limit_reached_status_badge(): void
    {
        $user = User::factory()->create();
        session([
            'assistant.conversation' => [
                ['role' => 'user', 'content' => 'Do something complicated'],
                ['role' => 'assistant', 'content' => "I wasn't able to finish that.", 'tools_used' => [], 'status' => 'limit_reached'],
            ],
        ]);

        $this->actingAs($user)->get('/assistant')->assertOk()->assertSee('Limit reached');
    }
}
