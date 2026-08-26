<?php

namespace Tests\Feature\Workflow;

use App\Enums\WorkflowScopeType;
use App\Enums\WorkflowType;
use App\Models\Team;
use App\Models\User;
use App\Models\WorkflowApproval;
use App\Models\WorkflowExecution;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Every new Phase 8 screen renders — see tests/Feature/Crm/
 * ViewRenderingTest.php for why this exists as its own file.
 */
class ViewRenderingTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_workflow_activity_index_renders(): void
    {
        $user = User::factory()->create();
        WorkflowExecution::factory()->create(['user_id' => $user->id]);

        $this->actingAs($user)->get('/workflows')->assertOk();
    }

    public function test_a_workflow_execution_show_page_renders_with_findings_and_approvals(): void
    {
        $user = User::factory()->create();
        $execution = WorkflowExecution::factory()->create([
            'user_id' => $user->id,
            'findings' => ['overdue_count' => 3],
            'result' => 'Three leads need attention.',
        ]);
        WorkflowApproval::factory()->create(['workflow_execution_id' => $execution->id, 'user_id' => $user->id]);

        $this->actingAs($user)->get("/workflows/{$execution->id}")
            ->assertOk()
            ->assertSee('Three leads need attention.');
    }

    public function test_a_user_cannot_view_another_users_execution(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $execution = WorkflowExecution::factory()->create(['user_id' => $owner->id]);

        $this->actingAs($other)->get("/workflows/{$execution->id}")->assertForbidden();
    }

    public function test_the_approvals_queue_renders_with_pending_and_decided_items(): void
    {
        $user = User::factory()->create();
        WorkflowApproval::factory()->create(['user_id' => $user->id]);
        WorkflowApproval::factory()->approved()->create(['user_id' => $user->id]);

        $this->actingAs($user)->get('/workflows/approvals')->assertOk();
    }

    public function test_the_manager_dashboard_shows_ai_insights(): void
    {
        $manager = User::factory()->manager()->create();
        WorkflowExecution::factory()->create([
            'user_id' => $manager->id,
            'workflow' => WorkflowType::DailyFollowUpReview,
            'scope_type' => WorkflowScopeType::Organisation,
            'result' => 'No overdue follow-ups organisation-wide.',
        ]);

        $this->actingAs($manager)->get('/dashboard')
            ->assertOk()
            ->assertSee('AI Insights')
            ->assertSee('No overdue follow-ups organisation-wide.');
    }

    public function test_the_team_head_dashboard_shows_ai_insights(): void
    {
        $team = Team::factory()->create();
        $teamHead = User::factory()->teamHead($team)->create();

        $this->actingAs($teamHead)->get('/dashboard')->assertOk()->assertSee('AI Insights');
    }

    public function test_the_team_member_dashboard_shows_ai_insights(): void
    {
        $member = User::factory()->create();

        $this->actingAs($member)->get('/dashboard')->assertOk()->assertSee('AI Insights');
    }

    public function test_the_dashboard_shows_a_pending_approval_link_when_one_exists(): void
    {
        $user = User::factory()->create();
        WorkflowApproval::factory()->create(['user_id' => $user->id]);

        $this->actingAs($user)->get('/dashboard')->assertOk()->assertSee('awaiting your approval');
    }
}
