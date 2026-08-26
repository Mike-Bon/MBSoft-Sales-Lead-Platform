<?php

namespace Tests\Feature\Communications;

use App\Models\MessageTemplate;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * STEP 17/19: any authenticated user may create a template scoped to
 * their own team; editing/removing one is restricted to its creator,
 * their Team Head, or a Manager.
 */
class MessageTemplateControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_team_member_creating_a_template_is_scoped_to_their_own_team_regardless_of_input(): void
    {
        $team = Team::factory()->create();
        $otherTeam = Team::factory()->create();
        $member = User::factory()->teamMember($team)->create();

        $this->actingAs($member)->post('/communications/templates', [
            'name' => 'Intro',
            'channel' => 'email',
            'body' => 'Hi {{first_name}}',
            'team_id' => $otherTeam->id, // must be ignored — never trusted from a non-Manager
        ])->assertRedirect(route('communications.templates.index'));

        $template = MessageTemplate::firstOrFail();
        $this->assertSame($team->id, $template->team_id);
        $this->assertSame($member->id, $template->created_by);
    }

    public function test_a_manager_may_choose_organisation_wide_or_a_specific_team(): void
    {
        $manager = User::factory()->manager()->create();
        $team = Team::factory()->create();

        $this->actingAs($manager)->post('/communications/templates', [
            'name' => 'Org-wide intro',
            'channel' => 'email',
            'body' => 'Hi {{first_name}}',
            'team_id' => $team->id,
        ])->assertRedirect(route('communications.templates.index'));

        $this->assertSame($team->id, MessageTemplate::firstOrFail()->team_id);
    }

    public function test_the_creator_can_edit_their_own_template(): void
    {
        $user = User::factory()->create();
        $template = MessageTemplate::factory()->create(['created_by' => $user->id, 'team_id' => null]);

        $this->actingAs($user)->put("/communications/templates/{$template->id}", [
            'name' => 'Updated name',
            'body' => 'Updated body',
            'status' => 'active',
        ])->assertRedirect(route('communications.templates.index'));

        $this->assertSame('Updated name', $template->fresh()->name);
    }

    public function test_a_different_team_member_cannot_edit_someone_elses_template(): void
    {
        $team = Team::factory()->create();
        $author = User::factory()->teamMember($team)->create();
        $otherMember = User::factory()->teamMember($team)->create();
        $template = MessageTemplate::factory()->create(['created_by' => $author->id, 'team_id' => $team->id]);

        $this->actingAs($otherMember)->put("/communications/templates/{$template->id}", [
            'name' => 'Hijacked',
            'body' => 'Hijacked body',
            'status' => 'active',
        ])->assertForbidden();

        $this->assertNotSame('Hijacked', $template->fresh()->name);
    }

    public function test_the_team_head_can_edit_their_teams_template(): void
    {
        $team = Team::factory()->create();
        $author = User::factory()->teamMember($team)->create();
        $teamHead = User::factory()->teamHead($team)->create();
        $template = MessageTemplate::factory()->create(['created_by' => $author->id, 'team_id' => $team->id]);

        $this->actingAs($teamHead)->put("/communications/templates/{$template->id}", [
            'name' => 'Refined by lead',
            'body' => 'Updated body',
            'status' => 'active',
        ])->assertRedirect(route('communications.templates.index'));

        $this->assertSame('Refined by lead', $template->fresh()->name);
    }
}
